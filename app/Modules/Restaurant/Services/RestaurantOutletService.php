<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Exceptions\OutletHasActiveConnectionException;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1C, Task B — first-class Outlet Management. Deliberately separate
 * from PosConnectionProvisioningService: an outlet is a real-world place
 * (one Workspace, one physical location) that exists independently of
 * whether it is ever connected to Petpooja at all — "Add Outlet" creates
 * one with NO POS connection, and it becomes usable in that SAME
 * workspace's "Use existing outlet" dropdown immediately.
 */
class RestaurantOutletService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function createOutlet(
        Workspace $workspace,
        string $name,
        ?string $address,
        ?string $timezone,
        ?AdminUser $actor = null,
    ): RestaurantOutlet {
        return DB::transaction(function () use ($workspace, $name, $address, $timezone, $actor) {
            // ACTIVE, not the old STATUS_PENDING default — Task B is explicit:
            // "Creates an active restaurant_outlets record with no POS
            // connection." An outlet needs no connection to be a real,
            // usable place.
            $outlet = RestaurantOutlet::create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'address' => $address,
                'timezone' => $timezone,
                'status' => RestaurantOutlet::STATUS_ACTIVE,
            ]);

            $this->auditLog->logAdmin(
                action: 'restaurant.outlet.created',
                targetType: RestaurantOutlet::class,
                targetId: $outlet->id,
                meta: ['workspace_id' => $workspace->id, 'outlet_name' => $outlet->name],
                admin: $actor,
            );

            return $outlet;
        });
    }

    public function updateOutlet(
        RestaurantOutlet $outlet,
        string $name,
        ?string $address,
        ?string $timezone,
        ?AdminUser $actor = null,
    ): RestaurantOutlet {
        return DB::transaction(function () use ($outlet, $name, $address, $timezone, $actor) {
            $outlet->update([
                'name' => $name,
                'address' => $address,
                'timezone' => $timezone,
            ]);

            $this->auditLog->logAdmin(
                action: 'restaurant.outlet.updated',
                targetType: RestaurantOutlet::class,
                targetId: $outlet->id,
                meta: ['workspace_id' => $outlet->workspace_id],
                admin: $actor,
            );

            return $outlet->refresh();
        });
    }

    /**
     * Atomically persists the two independent Phase 2 messaging preferences.
     *
     * This is intentionally the only settings-write path: it records both the
     * before and resulting values with the correct actor type, and it performs
     * no delivery, dispatch, event, webhook, or HTTP side effect. Future
     * delivery jobs must read these flags when they are introduced; this method
     * merely stores operator intent.
     */
    public function updateMessagingSettings(
        RestaurantOutlet $outlet,
        bool $digitalBillEnabled,
        bool $feedbackRequestEnabled,
        AdminUser|User $actor,
    ): RestaurantOutlet {
        return DB::transaction(function () use ($outlet, $digitalBillEnabled, $feedbackRequestEnabled, $actor) {
            $oldValues = [
                'digital_bill_enabled' => (bool) $outlet->digital_bill_enabled,
                'feedback_request_enabled' => (bool) $outlet->feedback_request_enabled,
            ];
            $newValues = [
                'digital_bill_enabled' => $digitalBillEnabled,
                'feedback_request_enabled' => $feedbackRequestEnabled,
            ];

            $outlet->update($newValues);

            if ($actor instanceof AdminUser) {
                $this->auditLog->logAdmin(
                    action: 'restaurant.outlet.messaging_settings_updated',
                    targetType: RestaurantOutlet::class,
                    targetId: $outlet->id,
                    meta: ['workspace_id' => $outlet->workspace_id, 'outlet_id' => $outlet->id],
                    admin: $actor,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    workspaceId: $outlet->workspace_id,
                );
            } else {
                // AuditLogService::log() resolves the authenticated web user,
                // which is this owner on the client-app request path. Passing
                // workspace_id explicitly keeps the durable audit row useful
                // outside a client-wide audit view as well.
                $this->auditLog->log(
                    action: 'restaurant.outlet.messaging_settings_updated',
                    auditable: $outlet,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    workspaceId: $outlet->workspace_id,
                );
            }

            return $outlet->refresh();
        });
    }

    /**
     * Refuses while the outlet still has a non-archived connection — see
     * OutletHasActiveConnectionException. The connection must be
     * paused/archived through PosConnectionProvisioningService first.
     */
    public function archiveOutlet(RestaurantOutlet $outlet, ?AdminUser $actor = null): RestaurantOutlet
    {
        if ($outlet->hasNonArchivedConnection()) {
            throw new OutletHasActiveConnectionException(
                'This outlet still has an active Petpooja connection. Pause or archive the connection before archiving the outlet.'
            );
        }

        return DB::transaction(function () use ($outlet, $actor) {
            $outlet->update(['status' => RestaurantOutlet::STATUS_ARCHIVED]);

            $this->auditLog->logAdmin(
                action: 'restaurant.outlet.archived',
                targetType: RestaurantOutlet::class,
                targetId: $outlet->id,
                meta: ['workspace_id' => $outlet->workspace_id],
                admin: $actor,
            );

            return $outlet->refresh();
        });
    }

    /**
     * Reverses archiveOutlet() — a reversible soft-archive, not a deletion.
     * Deliberately does NOT touch any connection: an archived outlet's
     * Petpooja connection (if it has one) stays archived until separately
     * restored through PosConnectionProvisioningService::restoreConnection()
     * and then explicitly resumed. Restoring the outlet only makes the
     * physical place selectable/manageable again — it must never silently
     * resume ingestion as a side effect.
     */
    public function restoreOutlet(RestaurantOutlet $outlet, ?AdminUser $actor = null): RestaurantOutlet
    {
        if ($outlet->status !== RestaurantOutlet::STATUS_ARCHIVED) {
            throw new \RuntimeException('Only an archived outlet can be restored.');
        }

        return DB::transaction(function () use ($outlet, $actor) {
            $outlet->update(['status' => RestaurantOutlet::STATUS_ACTIVE]);

            $this->auditLog->logAdmin(
                action: 'restaurant.outlet.restored',
                targetType: RestaurantOutlet::class,
                targetId: $outlet->id,
                meta: ['workspace_id' => $outlet->workspace_id],
                admin: $actor,
            );

            return $outlet->refresh();
        });
    }

    /**
     * Gate 5 of the six-gate Petpooja live activation invariant
     * ("outlet-specific authorization") — the smallest durable, auditable
     * operational record for a concept that otherwise has no persisted
     * representation anywhere in this codebase. This is a deliberate ADMIN
     * verification action (an operator has confirmed this physical outlet
     * is who it says it is and may go live), not a client self-service
     * acceptance — no such UI is required for this slice, and this method,
     * reached only through its own permission-gated admin route, is what
     * keeps that distinction real instead of a database-only workaround.
     *
     * No separate "revoke" method exists in this slice: archiving the
     * outlet already blocks everything downstream (a live connection
     * cannot be activated on an archived outlet, and hasNonArchivedConnection()
     * already blocks archiving an outlet with a live connection on it), and
     * authorization deliberately persists through an archive/restore cycle
     * — the same way a connection's token and restID persist through its
     * own archive/restore.
     */
    public function authorizeForLivePos(RestaurantOutlet $outlet, ?AdminUser $actor = null): RestaurantOutlet
    {
        return DB::transaction(function () use ($outlet, $actor) {
            $outlet->update([
                'pos_live_authorized_at' => now(),
                'pos_live_authorized_by_admin_id' => $actor?->id,
            ]);

            $this->auditLog->logAdmin(
                action: 'restaurant.outlet.pos_live_authorized',
                targetType: RestaurantOutlet::class,
                targetId: $outlet->id,
                meta: ['workspace_id' => $outlet->workspace_id],
                admin: $actor,
            );

            return $outlet->refresh();
        });
    }
}
