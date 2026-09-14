<?php

namespace App\Modules\Flows\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Platform-generated RSA material for one Meta-registered business phone number.
 *
 * `endpoint_token` is a 256-bit opaque routing capability for the public Flow
 * data-exchange endpoint. It deliberately is not derived from a phone number,
 * workspace ID, UUID, or any other customer identifier: that endpoint has no
 * session or workspace context, and must discover its one permitted tenant from
 * a value that cannot be enumerated.
 */
class WhatsappFlowKeyPair extends Model
{
    use BelongsToWorkspace;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ROTATED = 'rotated';

    public const STATUS_REVOKED = 'revoked';

    public const UPLOAD_NOT_UPLOADED = 'not_uploaded';

    public const UPLOAD_UPLOADED = 'uploaded';

    public const UPLOAD_FAILED = 'upload_failed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ROTATED, self::STATUS_REVOKED];

    public const UPLOAD_STATUSES = [self::UPLOAD_NOT_UPLOADED, self::UPLOAD_UPLOADED, self::UPLOAD_FAILED];

    protected $fillable = [
        'workspace_id', 'whatsapp_phone_number_id', 'public_key_pem', 'private_key_pem',
        'key_version', 'meta_upload_status', 'meta_uploaded_at', 'meta_upload_error', 'status', 'rotated_at',
    ];

    protected $hidden = ['private_key_pem', 'endpoint_token'];

    protected function casts(): array
    {
        return [
            'private_key_pem' => 'encrypted',
            'meta_uploaded_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $keyPair): void {
            $keyPair->uuid ??= (string) Str::uuid();
            $keyPair->endpoint_token ??= bin2hex(random_bytes(32));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<WhatsappPhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsappPhoneNumber::class, 'whatsapp_phone_number_id');
    }

    /**
     * Resolves exactly one active key for an unauthenticated Flow endpoint.
     *
     * There can be no ambient workspace before this lookup: the random token is
     * the routing boundary, and the matched row supplies the only private key
     * that may decrypt the request. Inactive and unknown tokens intentionally
     * both resolve to null so the public response cannot reveal key lifecycle.
     */
    public static function findActiveByEndpointToken(string $token): ?self
    {
        return static::withoutWorkspaceScope(
            'reason: public Flow data-exchange routing has no tenant context; the opaque endpoint token is the sole, unique routing boundary.'
        )
            ->where('endpoint_token', $token)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }
}
