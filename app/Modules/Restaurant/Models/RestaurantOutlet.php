<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Database\Factories\RestaurantOutletFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string|null $address
 * @property string|null $timezone
 * @property string $status
 * @property bool $digital_bill_enabled
 * @property bool $feedback_request_enabled
 * @property Carbon|null $pos_live_authorized_at
 * @property int|null $pos_live_authorized_by_admin_id
 */
class RestaurantOutlet extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<RestaurantOutletFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    /**
     * ⚠️ There is no STATUS_PENDING (or STATUS_INACTIVE) here — deliberately.
     * An outlet's lifecycle is exactly these two states, enforced by a DB
     * CHECK constraint (`restaurant_outlets_status_check`), not merely by
     * this class only ever writing one of them.
     *
     * A former STATUS_PENDING existed here and was the actual defect fixed
     * by the `normalize_pending_outlet_status_to_active` migration: the
     * outlets table's own column default was 'pending' (predating Phase
     * 1C's outlet-management work), so any outlet created by a path that
     * relied on that default — rather than going through
     * RestaurantOutletService, which always writes 'active' — silently
     * carried a status that `eligibleForNewConnection()` never matches.
     * The outlet looked "Not connected" in the UI (correctly — it had zero
     * Petpooja connections) yet could never actually be selected to
     * connect one. "Pending" is a POS CONNECTION lifecycle concept
     * (PosConnection::STATUS_PENDING) — it was never a meaningful state for
     * the physical outlet itself, which either exists and is usable
     * (active) or has been retired (archived). Do not reintroduce it.
     */
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'workspace_id',
        'name',
        'address',
        'timezone',
        'status',
        'digital_bill_enabled',
        'feedback_request_enabled',
        'pos_live_authorized_at',
        'pos_live_authorized_by_admin_id',
    ];

    protected $casts = [
        'digital_bill_enabled' => 'boolean',
        'feedback_request_enabled' => 'boolean',
        'pos_live_authorized_at' => 'datetime',
    ];

    protected static function newFactory(): RestaurantOutletFactory
    {
        return RestaurantOutletFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * BelongsToWorkspace deliberately does not define this relation itself
     * (see the trait's own docblock) — each scoped model declares its own,
     * the same way PosConnection and PosWebhookEvent do. Unlike those two,
     * this one carries no "not scoped" caveat: RestaurantOutlet IS scoped by
     * the trait's global scope, this is just the normal relation.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Deliberately hasMany, not hasOne: an outlet can retain prior/inactive
     * POS connection history (a restID rotation, a reconnect after
     * disconnection) rather than being restricted to exactly one connection
     * at a time. No "which connection is active" resolver here by design —
     * that is later service-layer work once Phase 1B's status semantics are
     * exercised in practice.
     *
     * @return HasMany<PosConnection, $this>
     */
    public function posConnections(): HasMany
    {
        return $this->hasMany(PosConnection::class, 'outlet_id');
    }

    /**
     * The outlet has a connection that is NOT archived — i.e. it already
     * occupies its one allowed active_slot. Used both to filter the
     * "existing outlet" dropdown (Task C) and to guard outlet archiving
     * (Task B: an outlet with a live connection cannot itself be archived
     * first).
     */
    public function hasNonArchivedConnection(): bool
    {
        return $this->posConnections()
            ->where('status', '!=', PosConnection::STATUS_ARCHIVED)
            ->exists();
    }

    /**
     * Gate 5 of the six-gate live activation invariant: an admin has
     * explicitly authorized THIS outlet for a live Petpooja connection.
     * "Authorized" is exactly "the timestamp is set" — see the migration
     * that added these two columns and RestaurantOutletService::authorizeForLivePos().
     */
    public function isAuthorizedForLivePos(): bool
    {
        return $this->pos_live_authorized_at !== null;
    }

    /**
     * Active outlets in $workspaceId with NO non-archived Petpooja
     * connection — exactly the "Use an existing outlet" eligibility rule
     * (Task C). An outlet belongs to exactly one workspace, so this is
     * scoped by workspace_id directly rather than relying on the caller to
     * filter afterward.
     *
     * `withoutWorkspaceScope()`: this takes an explicit $workspaceId
     * parameter and its own where() IS the boundary — the same fail-open
     * shape as LegalAcceptance::currentFor() and UsageMeter::current().
     * Measured: without the bypass this returned EMPTY for every workspace
     * when called from an admin controller (no ambient tenant context),
     * which would have made every outlet look ineligible platform-wide.
     *
     * `@return Builder<static>` would be the usual shape for a model method,
     * but the static call below resolves concretely to RestaurantOutlet, not
     * a late-static-bound type — there are no subclasses, so this matches
     * what is actually returned rather than widening to satisfy the label.
     *
     * @return Builder<RestaurantOutlet>
     */
    public static function eligibleForNewConnection(int $workspaceId): Builder
    {
        return static::withoutWorkspaceScope('reason: takes an explicit workspace_id parameter and its own where() IS the boundary; fails open like LegalAcceptance::currentFor().')
            ->where('workspace_id', $workspaceId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereDoesntHave('posConnections', fn ($q) => $q->where('status', '!=', PosConnection::STATUS_ARCHIVED));
    }
}
