<?php

namespace App\Modules\SmartQr\Models;

use Database\Factories\SmartQrCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Scopes\WorkspaceScope;
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
    use HasFactory;

    /**
     * ⚠️ Module models live outside app/Models, so Laravel's convention
     * resolves Database\\Factories\\Modules\\SmartQr\\Models\\…Factory and finds
     * nothing. Named explicitly, as the AI and Social module models do.
     */
    protected static function newFactory(): SmartQrCodeFactory
    {
        return SmartQrCodeFactory::new();
    }

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
     * ─── ⚠️ THE WORKSPACE SCOPE COMES OFF, AND IT HAS TO ────────────────────
     *
     * Found in slice 3, by an assertion failing: `isAssigned()` returned FALSE
     * for a code that demonstrably had a current assignment row.
     *
     * `SmartQrAssignment` is workspace-scoped and the scope fails CLOSED, so
     * with no ambient workspace context it ANDs `1 = 0` onto this relation and
     * the code reports itself unassigned — to the admin inventory, to the
     * assignment action's duplicate check, and to anything else asking the
     * question outside a tenant request. "No code is ever assigned" is a
     * dangerous answer for the one check standing between a code and two
     * tenants holding it.
     *
     * ⚠️ "Which tenant currently holds this code" is inherently a CROSS-TENANT
     * question — it is asked precisely when the answer is not yet known — and
     * this model is lifecycle-owned, so it has no scope of its own to inherit.
     * Bounding the relation would answer a different question than the one it
     * is named for.
     *
     * The tenant boundary is NOT lost: `SmartQrAccess::boundedTo()` applies an
     * explicit `workspace_id` to every customer-facing use of this relation, and
     * that explicit filter always was the boundary — the scope on top of it was
     * the H-2 shape that made the slice-1 canary return 0.
     *
     * @return HasOne<SmartQrAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(SmartQrAssignment::class)
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereNull('unassigned_at');
    }

    public function isAssigned(): bool
    {
        return $this->currentAssignment()->exists();
    }
}
