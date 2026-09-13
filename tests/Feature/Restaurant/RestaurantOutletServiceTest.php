<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Exceptions\OutletHasActiveConnectionException;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantOutletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RestaurantOutletService
    {
        return app(RestaurantOutletService::class);
    }

    #[Test]
    public function creating_an_outlet_starts_active_with_no_connection(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $outlet = $this->service()->createOutlet($workspace, 'Burger King', '221B Baker Street', 'Asia/Kolkata');

        $this->assertSame($workspace->id, $outlet->workspace_id);
        $this->assertSame('Burger King', $outlet->name);
        $this->assertSame('221B Baker Street', $outlet->address);
        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->status);
        $this->assertSame(0, $outlet->posConnections()->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.outlet.created',
            'auditable_id' => $outlet->id,
        ]);
    }

    /**
     * ⚠️ Pins the actual reported defect directly: creating an outlet named
     * "Burger King" must persist and render "Burger King" — never a stale
     * "Food Court" from unrelated prior state.
     */
    #[Test]
    public function the_created_outlet_uses_the_entered_name_exactly(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Food Court']);

        $outlet = $this->service()->createOutlet($workspace, 'Burger King', null, null);

        $this->assertSame('Burger King', $outlet->fresh()->name);
        $this->assertNotSame('Food Court', $outlet->fresh()->name);
    }

    #[Test]
    public function an_outlet_is_visible_only_in_its_own_workspaces_eligible_dropdown(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $outlet = $this->service()->createOutlet($workspaceA, 'Only In A', null, null);

        $this->assertTrue(
            RestaurantOutlet::eligibleForNewConnection($workspaceA->id)->where('id', $outlet->id)->exists()
        );
        $this->assertFalse(
            RestaurantOutlet::eligibleForNewConnection($workspaceB->id)->where('id', $outlet->id)->exists(),
            'An outlet must never appear in another workspace\'s eligible outlet list.'
        );
    }

    #[Test]
    public function a_connected_pending_or_paused_outlet_is_excluded_from_the_eligible_dropdown(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connectionService = app(PosConnectionProvisioningService::class);

        $pendingOutlet = $this->service()->createOutlet($workspace, 'Pending Outlet', null, null);
        $connectionService->createSandboxConnection($pendingOutlet, 'REST-ELIG-PENDING', null);

        $connectedOutlet = $this->service()->createOutlet($workspace, 'Connected Outlet', null, null);
        $connectedConnection = $connectionService->createSandboxConnection($connectedOutlet, 'REST-ELIG-CONNECTED', null);
        $connectionService->generateToken($connectedConnection);
        $connectionService->activateSandbox($connectedConnection->fresh());

        $pausedOutlet = $this->service()->createOutlet($workspace, 'Paused Outlet', null, null);
        $pausedConnection = $connectionService->createSandboxConnection($pausedOutlet, 'REST-ELIG-PAUSED', null);
        $connectionService->generateToken($pausedConnection);
        $connectionService->activateSandbox($pausedConnection->fresh());
        $connectionService->pauseConnection($pausedConnection->fresh());

        $freeOutlet = $this->service()->createOutlet($workspace, 'Free Outlet', null, null);

        $eligibleIds = RestaurantOutlet::eligibleForNewConnection($workspace->id)->pluck('id')->all();

        $this->assertNotContains($pendingOutlet->id, $eligibleIds);
        $this->assertNotContains($connectedOutlet->id, $eligibleIds);
        $this->assertNotContains($pausedOutlet->id, $eligibleIds);
        $this->assertContains($freeOutlet->id, $eligibleIds);
    }

    #[Test]
    public function an_archived_connection_outlet_becomes_eligible_again(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connectionService = app(PosConnectionProvisioningService::class);

        $outlet = $this->service()->createOutlet($workspace, 'Rotating Outlet', null, null);
        $connection = $connectionService->createSandboxConnection($outlet, 'REST-ELIG-ARCHIVED', null);
        $connectionService->archiveConnection($connection);

        $eligibleIds = RestaurantOutlet::eligibleForNewConnection($workspace->id)->pluck('id')->all();
        $this->assertContains($outlet->id, $eligibleIds);
    }

    #[Test]
    public function archiving_an_outlet_with_an_active_connection_is_blocked(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = $this->service()->createOutlet($workspace, 'Blocked Archive', null, null);
        app(PosConnectionProvisioningService::class)->createSandboxConnection($outlet, 'REST-BLOCK-ARCHIVE', null);

        $this->expectException(OutletHasActiveConnectionException::class);
        $this->service()->archiveOutlet($outlet);
    }

    #[Test]
    public function archiving_an_outlet_with_no_connection_succeeds(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = $this->service()->createOutlet($workspace, 'Free To Archive', null, null);

        $archived = $this->service()->archiveOutlet($outlet);

        $this->assertSame(RestaurantOutlet::STATUS_ARCHIVED, $archived->status);
    }
}
