<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2. `RestaurantBill` is `BelongsToWorkspace`-scoped from
 * birth (unlike `PosConnection`/`PosWebhookEvent` — see the model docblock
 * for why that split is correct). No HTTP surface exists for it yet, so this
 * asserts scoping at the model/query level rather than through a route.
 *
 * Every negative assertion is paired with a positive control on the SAME
 * query shape for the legitimate workspace, per this repo's isolation-test
 * convention — a workspace seeing nothing is equally consistent with "scoped
 * correctly" and "the query is broken", so each context must also prove it
 * CAN see its own row.
 */
class RestaurantBillWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    #[Test]
    public function a_workspace_sees_only_its_own_bill_never_the_others(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $billA = RestaurantBill::factory()->create([
            'workspace_id' => $workspaceA->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspaceA->id]),
            'external_order_id' => 'A-1',
        ]);
        $billB = RestaurantBill::factory()->create([
            'workspace_id' => $workspaceB->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspaceB->id]),
            'external_order_id' => 'B-1',
        ]);

        // Positive control: workspace A's context genuinely finds its own row.
        $foundA = WorkspaceContext::for($workspaceA->id, fn () => RestaurantBill::find($billA->id));
        $this->assertNotNull($foundA, 'Sanity check: workspace A must see its own bill.');
        $this->assertSame($billA->id, $foundA->id);

        // Negative: the SAME context, the OTHER workspace's row.
        $blockedB = WorkspaceContext::for($workspaceA->id, fn () => RestaurantBill::find($billB->id));
        $this->assertNull($blockedB, 'Workspace A must never see workspace B\'s bill.');

        // Mirror in the other direction, so this is not a one-way accident.
        $foundB = WorkspaceContext::for($workspaceB->id, fn () => RestaurantBill::find($billB->id));
        $this->assertNotNull($foundB);
        $this->assertSame($billB->id, $foundB->id);

        $blockedA = WorkspaceContext::for($workspaceB->id, fn () => RestaurantBill::find($billA->id));
        $this->assertNull($blockedA);
    }

    #[Test]
    public function no_workspace_context_fails_closed_rather_than_leaking_every_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        RestaurantBill::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspace->id]),
            'external_order_id' => 'NOCTX-1',
        ]);

        WorkspaceContext::flush();

        $this->assertSame(0, RestaurantBill::query()->count(), 'With no context, the scope must match nothing — not everything.');
    }

    #[Test]
    public function a_cross_tenant_context_can_still_see_every_workspaces_bills(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        RestaurantBill::factory()->create([
            'workspace_id' => $workspaceA->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspaceA->id]),
            'external_order_id' => 'X-1',
        ]);
        RestaurantBill::factory()->create([
            'workspace_id' => $workspaceB->id,
            'connection_id' => PosConnection::factory()->create(['workspace_id' => $workspaceB->id]),
            'external_order_id' => 'X-2',
        ]);

        $count = WorkspaceContext::crossTenant('reason: sweep/report scanning every workspace', fn () => RestaurantBill::query()->count());

        $this->assertSame(2, $count, 'A deliberately cross-tenant context is a door, not a leak — it must see both.');
    }
}
