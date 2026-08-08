<?php

namespace App\Modules\Shared\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A connected messaging channel (WhatsApp, Messenger, Instagram, SMS) belonging
 * to one workspace.
 *
 * The @property annotations below are not decoration: without them PHPStan
 * reports `property.notFound` on every attribute access, because
 * `checkModelProperties: false` is off in phpstan.neon and larastan cannot
 * infer columns for this model. BUG-002 records that the codebase should either
 * annotate these properly or baseline them as a tracked decision — this is the
 * decision, tracked.
 *
 * Effect measured: MessengerProfileTestCommand 4 errors -> 1, and Modules/Shared
 * + Modules/Inbox 25 -> 23.
 *
 * ⚠️ The one remaining error in MessengerProfileTestCommand is a `catch.neverThrown`
 * on the `credentials` read, and it is a FALSE negative introduced by the
 * annotation below: typing `credentials` as an array tells PHPStan the access
 * cannot throw, but the `encrypted:array` cast throws DecryptException on
 * corrupt ciphertext at runtime — which is precisely the condition that command
 * exists to diagnose. The catch is live. It is left in place, and the error is
 * left unsuppressed, because deleting a real safety net to satisfy a static
 * analyser would be the wrong trade.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $channel
 * @property string|null $provider
 * @property array<string, mixed>|null $credentials
 * @property string $display_name
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string|null $status
 * @property array<string, mixed>|null $meta_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChannelAccount extends Model
{
    /**
     * Phase 0, slice 7 — completing the Shared module.
     *
     * id route key. THE INBOUND ROUTING MODEL: every WhatsApp, Messenger and
     * Instagram message is routed by matching an identifier on this table.
     *
     * Its prerequisite was closed in slice 6, BEFORE this trait. Both
     * ChannelAccountRouting methods drop the scope explicitly, one query wide,
     * and the service is in the bypass inventory — findForInbound() runs with no
     * authenticated user and the workspace is the ANSWER it seeks, while
     * resolveForAttach() must see across workspaces to detect a cross-workspace
     * claim at all (BUG-019).
     */
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'channel', 'provider', 'credentials',
        'display_name', 'phone_number_id', 'business_account_id', 'status', 'meta_json',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta_json' => 'array',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
