<?php

namespace App\Modules\Whatsapp\Models;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use Database\Factories\WhatsappBusinessAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Meta WhatsApp Business Account connected to one workspace.
 *
 * ⚠️ @property annotations added following the decision recorded on
 * ChannelAccount for BUG-002: annotate properly rather than let
 * `property.notFound` accumulate. `checkModelProperties` is on and larastan
 * cannot infer columns for this model.
 *
 * Types are taken from the live schema, not guessed:
 * `credentials`, `webhook_verify_token`, `webhook_verify_token_hash` and
 * `meta_json` are all NULLABLE columns, and typing them non-null would have
 * been a worse lie than the missing annotation.
 *
 * ⚠️ CORRECTION — an earlier version of this comment claimed the annotation
 * caused, and would remove, the `nullsafe.neverNull` warnings on
 * `WhatsappSetupController`. THAT WAS WRONG, twice over.
 *
 * It was wrong on cause: module-wide `nullsafe.neverNull` measured 4 before the
 * annotation and 4 after, so the annotation neither created nor removed them.
 * PHPStan was never inferring the model non-null — a `dumpType` probe on
 * `WhatsappBusinessAccount::where(...)->first()` returns
 * `WhatsappBusinessAccount|null`, correctly.
 *
 * It was wrong on substance: those warnings were not spurious. Both sites sat
 * on the LEFT of `??`, and in PHP 8 a property read on null under `??` is
 * suppressed and yields null. So `$x?->p ?? $d` and `$x->p ?? $d` are identical
 * in every case INCLUDING null — there is no null dereference in either form,
 * and PHPStan flags the `?->` as genuinely redundant for exactly that reason.
 * Both have been simplified to `->`.
 *
 * ⚠️ That reasoning applies ONLY to `?->` sitting on the left of `??`. It says
 * nothing about a bare `$x->p` with no coalesce, which does still fatal on null,
 * and it is unrelated to the DecryptException point below — do not merge the
 * two.
 *
 * ⚠️ `credentials` is `encrypted:array` and `webhook_verify_token` is
 * `encrypted`, so the annotated types describe the value AFTER the cast. As
 * ChannelAccount records, typing a decrypted attribute can make PHPStan believe
 * the access cannot throw, while at runtime a corrupt payload raises
 * DecryptException. Any catch around a read of these two is live — do not
 * remove one because the tool calls it unreachable.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $waba_id
 * @property array<string, mixed>|null $credentials
 * @property string|null $webhook_verify_token
 * @property string|null $webhook_verify_token_hash
 * @property string $status
 * @property array<string, mixed>|null $meta_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsappBusinessAccount extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return WhatsappBusinessAccountFactory::new();
    }

    protected $table = 'whatsapp_business_accounts';

    protected $fillable = [
        'workspace_id', 'waba_id', 'credentials', 'webhook_verify_token', 'webhook_verify_token_hash', 'status', 'meta_json',
    ];

    protected $hidden = ['credentials', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_verify_token' => 'encrypted',
            'meta_json' => 'array',
        ];
    }

    /**
     * ⚠️ TYPED, and not decoration. Without the generics `->first()` on this
     * relation degrades to a bare Illuminate\...\Model, so reading
     * `->phone_number_id` off it was `property.notFound` — an error that looked
     * like a missing @property on THIS model but belongs to the related one.
     *
     * The form matches the Partner / Client / User precedent already in the
     * codebase for larastan v3.9.6: <TRelatedModel, TDeclaringModel>, which is
     * the pair PHPStan names in its own message.
     *
     * @return HasMany<WhatsappPhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsappPhoneNumber::class, 'waba_id_fk');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WhatsappTemplate::class, 'waba_id', 'waba_id');
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** O(1) lookup for per-WABA webhook routes (token is stored encrypted). */
    public static function findByWebhookToken(string $token): ?self
    {
        return static::where('webhook_verify_token_hash', static::hashWebhookToken($token))->first();
    }

    /** Access token for Graph API (embedded OAuth or manual system user). */
    public function accessToken(): ?string
    {
        $creds = $this->credentials ?? [];

        return $creds['system_user_token'] ?? $creds['access_token'] ?? null;
    }

    public static function resolveAccessTokenForWorkspace(int $workspaceId): ?string
    {
        $waba = static::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();

        if ($waba) {
            $token = $waba->accessToken();
            if ($token) {
                return $token;
            }
        }

        return CredentialResolver::system()->meta()?->systemUserToken();
    }

    public static function defaultPhoneNumberIdForWorkspace(int $workspaceId): ?string
    {
        $fromChannel = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->whereNotNull('phone_number_id')
            ->orderBy('id')
            ->value('phone_number_id');

        if ($fromChannel) {
            return (string) $fromChannel;
        }

        $waba = static::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('phoneNumbers')
            ->first();

        return $waba?->phoneNumbers->first()?->phone_number_id;
    }

    protected static function booted(): void
    {
        static::saving(function (self $waba) {
            if ($waba->isDirty('webhook_verify_token') && $waba->webhook_verify_token) {
                $waba->webhook_verify_token_hash = static::hashWebhookToken($waba->webhook_verify_token);
            }
        });
    }
}
