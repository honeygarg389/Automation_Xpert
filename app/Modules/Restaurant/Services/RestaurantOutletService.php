<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
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
}
