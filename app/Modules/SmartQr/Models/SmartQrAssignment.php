<?php

namespace App\Modules\SmartQr\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Where tenancy lives — and where it ends.
 *
 * ⚠️ THIS model IS workspace-scoped, and that is not a contradiction of
 * SmartQrCode's exemption. The code is lifecycle-owned; the ASSIGNMENT is
 * unambiguously tenant data for its whole life. A row here belongs to the
 * workspace named in its column from the moment it is written, which is exactly
 * the question the coverage guard's standard asks.
 *
 * The trait also gives the reassignment rule for free: a previous tenant's
 * assignment carries THEIR workspace_id, so the new tenant's scoped queries
 * cannot see it, and neither can any child row keyed by it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $smart_qr_code_id
 */
class SmartQrAssignment extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'uuid', 'smart_qr_code_id', 'workspace_id', 'channel_account_id', 'assigned_user_id',
        'name', 'qr_type', 'default_message', 'status', 'assigned_at', 'unassigned_at',
        'starts_at', 'expires_at', 'assigned_by_admin_id', 'config_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime', 'unassigned_at' => 'datetime',
            'starts_at' => 'datetime', 'expires_at' => 'datetime',
            'config_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->uuid ??= (string) Str::uuid();
            $a->assigned_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isCurrent(): bool
    {
        return $this->unassigned_at === null;
    }

    /** @return BelongsTo<SmartQrCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(SmartQrCode::class, 'smart_qr_code_id');
    }

    /** @return HasMany<SmartQrScanEvent, $this> */
    public function scanEvents(): HasMany
    {
        return $this->hasMany(SmartQrScanEvent::class);
    }
}
