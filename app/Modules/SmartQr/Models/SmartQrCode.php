<?php

namespace App\Modules\SmartQr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ⚠️ A LIFECYCLE-OWNED ROW: platform-owned at birth, tenant-owned on assignment.
 *
 * This model deliberately does NOT use `BelongsToWorkspace`, and the table
 * deliberately has NO `workspace_id` column.
 *
 * ─── Why the trait would be wrong, not merely unnecessary ───────────────────
 *
 * The scope fails closed. An unassigned code has no workspace, so
 * `where workspace_id = :id` matches for NO value of :id — the code would be
 * invisible to every tenant AND to the Super Admin inventory screen whose entire
 * purpose is those rows. A nullable column would not help: NULL satisfies no
 * equality comparison.
 *
 * This is the `Partner` shape from Phase 0 — the coverage guard matches on a
 * COLUMN NAME, not on ownership — but worse, because ownership CHANGES during
 * the lifecycle, so no static classification is right for the whole life.
 *
 * ─── ⚠️ SO WHAT PROTECTS TENANCY? ───────────────────────────────────────────
 *
 * `SmartQrAccess`, and nothing else. Because there is no scope, a raw
 * `SmartQrCode::where(...)` in customer-facing code is a cross-tenant read with
 * nothing to stop it — the failure is SILENT, which is why it is made impossible
 * rather than remembered:
 *
 *   `SmartQrAccessGuardTest` fails the build if `SmartQrCode::` is queried
 *   anywhere outside SmartQrAccess and the admin namespace.
 *
 * That guard shipped in the same commit as this model, deliberately. A guard
 * that lags behind the thing it guards is a guard for the next mistake, not this
 * one.
 *
 * @property int $id
 * @property string $serial_number
 * @property string $public_token
 */
class SmartQrCode extends Model
{
    protected $fillable = ['serial_number', 'public_token', 'batch_id', 'status', 'printed_at'];

    protected function casts(): array
    {
        return ['printed_at' => 'datetime'];
    }

    /**
     * ⚠️ Routed by the PRINTED SERIAL for admin surfaces, never by public_token.
     *
     * The two identifiers are different values with different jobs:
     * `serial_number` is printed on the artwork and is public by definition;
     * `public_token` is what the QR encodes and must be unguessable. Binding an
     * admin route to the token would put it in URLs, logs and referers.
     */
    public function getRouteKeyName(): string
    {
        return 'serial_number';
    }

    /** @return BelongsTo<SmartQrBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SmartQrBatch::class, 'batch_id');
    }

    /** @return HasMany<SmartQrAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SmartQrAssignment::class);
    }

    /**
     * The CURRENT assignment, if any.
     *
     * "Current" is `unassigned_at IS NULL` — not `status = active`, which is a
     * different question (a code can be assigned but deactivated).
     *
     * @return HasOne<SmartQrAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(SmartQrAssignment::class)->whereNull('unassigned_at');
    }

    public function isAssigned(): bool
    {
        return $this->currentAssignment()->exists();
    }
}
