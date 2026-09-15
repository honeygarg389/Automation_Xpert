<?php

namespace Tests\Feature\Restaurant\Admin;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantOutletAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $permissionKeys */
    private function adminWith(array $permissionKeys): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_ROLE_'.uniqid(), 'name' => 'Test Role', 'description' => 'test']);

        foreach ($permissionKeys as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    #[Test]
    public function add_outlet_creates_an_active_unconnected_outlet_under_the_selected_workspace(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.store'), [
            'workspace_id' => $workspace->id,
            'name' => 'Burger King',
            'address' => '1 High Street',
        ])->assertSessionHasNoErrors();

        $outlet = RestaurantOutlet::query()->where('name', 'Burger King')->firstOrFail();
        $this->assertSame($workspace->id, $outlet->workspace_id);
        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->status);
        $this->assertSame(0, $outlet->posConnections()->count());
    }

    #[Test]
    public function admin_without_permission_cannot_add_an_outlet(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.outlets.store'), ['workspace_id' => $workspace->id, 'name' => 'X'])
            ->assertForbidden();
    }

    #[Test]
    public function outlets_index_lists_and_searches_by_workspace_and_name(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Searchable Outlet']);
        RestaurantOutlet::factory()->create(['name' => 'Unrelated Outlet']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index', ['search' => 'Searchable']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('outlets.total', 1));
    }

    #[Test]
    public function editing_an_outlet_updates_its_details(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.update', $outlet), [
            'name' => 'New Name',
            'address' => 'New Address',
        ])->assertSessionHasNoErrors();

        $this->assertSame('New Name', $outlet->fresh()->name);
        $this->assertSame('New Address', $outlet->fresh()->address);
    }

    #[Test]
    public function archiving_an_outlet_with_no_connection_succeeds(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.archive', $outlet))
            ->assertSessionHasNoErrors();

        $this->assertSame(RestaurantOutlet::STATUS_ARCHIVED, $outlet->fresh()->status);
    }

    #[Test]
    public function archiving_an_outlet_with_an_active_connection_is_blocked_with_a_friendly_error(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        app(PosConnectionProvisioningService::class)->createSandboxConnection($outlet, 'REST-OUTLET-ARCHIVE', null);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.archive', $outlet));

        $response->assertSessionHas('error');
        $this->assertNotSame(RestaurantOutlet::STATUS_ARCHIVED, $outlet->fresh()->status);
    }

    // ══ Section H — Add Outlet Only → Connect Petpooja now ═══════════════

    #[Test]
    public function adding_an_outlet_flashes_the_clear_not_connected_message_and_a_connect_cta(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.store'), [
            'workspace_id' => $workspace->id,
            'name' => 'Brand New Branch',
        ]);

        $response->assertSessionHas('success', 'Outlet added. It is not connected to Petpooja yet.');

        $outlet = RestaurantOutlet::query()->where('name', 'Brand New Branch')->firstOrFail();
        $response->assertSessionHas('connectCta', [
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'outlet_name' => 'Brand New Branch',
        ]);
    }

    /**
     * The CTA's destination page must actually honour the preselect — and,
     * separately, must never carry a restID anywhere in its props. There is
     * no Petpooja outlet-lookup API; a prefilled restID would look like one.
     */
    #[Test]
    public function the_connect_cta_preselects_the_correct_workspace_and_outlet_with_no_restid_prefilled(): void
    {
        $admin = $this->adminWith(['view_pos_connections', 'manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => RestaurantOutlet::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.connections.create', [
                'workspace_id' => $workspace->id,
                'outlet_id' => $outlet->id,
            ]))
            ->assertOk()
            ->assertInertia(function ($page) use ($workspace, $outlet) {
                $page->where('preselect.workspace_id', $workspace->id);
                $page->where('preselect.outlet_id', $outlet->id);

                $encoded = json_encode($page->toArray());
                $this->assertStringNotContainsString('external_ref', $encoded,
                    'The create page must never suggest or prefill a restID — there is no lookup API.');
            });
    }

    // ══ Reversible Archive / Restore ═════════════════════════════════════

    #[Test]
    public function the_default_outlets_index_shows_only_active_outlets(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        RestaurantOutlet::factory()->create(['name' => 'Active One']);
        RestaurantOutlet::factory()->create(['name' => 'Archived One', 'status' => RestaurantOutlet::STATUS_ARCHIVED]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                /** @var list<array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['outlets']['data'];
                $names = array_column($rows, 'name');
                $this->assertContains('Active One', $names);
                $this->assertNotContains('Archived One', $names);
            });
    }

    /** The Archived tab, and its inverse (Active tab excludes archived) — the positive control alongside it. */
    #[Test]
    public function archived_outlets_appear_only_under_the_archived_filter(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        RestaurantOutlet::factory()->create(['name' => 'Retired Branch', 'status' => RestaurantOutlet::STATUS_ARCHIVED]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('filters.status', 'archived');
                /** @var list<array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['outlets']['data'];
                $this->assertContains('Retired Branch', array_column($rows, 'name'));
            });
    }

    #[Test]
    public function restore_outlet_changes_status_from_archived_to_active(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['status' => RestaurantOutlet::STATUS_ARCHIVED]);

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.restore', $outlet))
            ->assertSessionHasNoErrors();

        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->fresh()->status);
    }

    #[Test]
    public function restore_outlet_is_refused_when_the_outlet_is_not_archived(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['status' => RestaurantOutlet::STATUS_ACTIVE]);

        $response = $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.restore', $outlet));

        $response->assertSessionHas('error');
        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->fresh()->status);
    }

    /**
     * Restoring the outlet alone must never resume its (still archived)
     * connection's ingress — the HTTP-level twin of the service-level proof
     * in PosConnectionLifecycleServiceTest.
     */
    #[Test]
    public function restoring_an_outlet_does_not_resume_its_archived_connections_ingress(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-OUTLET-RESTORE-1', null);
        $token = $service->generateToken($connection);
        $service->activateSandbox($connection->fresh());
        $service->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.restore', $outlet->fresh()))
            ->assertSessionHasNoErrors();

        $this->assertSame(RestaurantOutlet::STATUS_ACTIVE, $outlet->fresh()->status);
        $this->assertSame(PosConnection::STATUS_ARCHIVED, $connection->fresh()->status);

        $this->postJson('/webhooks/pos/petpooja', [
            'event' => 'orderdetails',
            'orderID' => 'ORD-1',
            'properties' => ['Restaurant' => ['restID' => 'REST-OUTLET-RESTORE-1']],
            'token' => $token,
        ])->assertStatus(403)->assertExactJson(['status' => 'rejected']);
    }

    #[Test]
    public function admin_without_permission_cannot_restore_an_outlet(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['status' => RestaurantOutlet::STATUS_ARCHIVED]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.outlets.restore', $outlet))
            ->assertForbidden();

        $this->assertSame(RestaurantOutlet::STATUS_ARCHIVED, $outlet->fresh()->status);
    }

    // ══ Outlet status × connection state are independent axes ═══════════

    /**
     * @param  array<string, mixed>  $pageArray
     * @return array<string, mixed>
     */
    private function findOutletRow(array $pageArray, string $uuid): array
    {
        foreach ($pageArray['props']['outlets']['data'] as $row) {
            if ($row['uuid'] === $uuid) {
                return $row;
            }
        }

        $this->fail("Outlet {$uuid} not found in the outlets index response.");
    }

    #[Test]
    public function an_outlet_with_no_connection_ever_shows_not_connected(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('not_connected', $row['connection_state']);
                $this->assertNull($row['connection_uuid']);
            });
    }

    /**
     * ⚠️ THE REGRESSION THIS PINS: an outlet whose only connection is
     * archived must show `archived`, never `not_connected` — those are
     * different facts (zero connection rows vs. one archived row), and
     * collapsing them hid a real, existing connection from the directory.
     */
    #[Test]
    public function an_outlet_with_an_archived_connection_shows_archived_not_not_connected(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-STATE-1', null);
        $service->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet, $connection) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('archived', $row['connection_state']);
                $this->assertSame($connection->uuid, $row['connection_uuid']);
            });
    }

    /**
     * The server-side half of "link labels accurately match their
     * behavior": Outlets/Index.jsx switches its row action's label
     * ("Manage Archived Connection" vs "Configure") purely on
     * `connection_state === 'archived'`. That link only ever NAVIGATES to
     * the connection detail page — it never itself calls the restore
     * route — so it must never read "Restore Connection" (Section:
     * Restore Connection UI flow, item 4). This pins the exact
     * `connection_state` value the frontend switches on; a non-archived
     * connection must read "Configure" instead.
     */
    #[Test]
    public function a_non_archived_connections_outlet_row_carries_the_configure_discriminant_not_archived(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        app(PosConnectionProvisioningService::class)->createSandboxConnection($outlet, 'REST-LABEL-1', null);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertNotSame('archived', $row['connection_state'],
                    'A pending/connected/paused connection must render the "Configure" label, never "Manage Archived Connection".');
            });
    }

    #[Test]
    public function an_active_outlet_with_an_archived_connection_remains_physically_active(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['status' => RestaurantOutlet::STATUS_ACTIVE]);
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-STATE-2', null);
        $service->archiveConnection($connection->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('active', $row['status']);
                $this->assertSame('archived', $row['connection_state']);
            });
    }

    #[Test]
    public function an_archived_outlet_with_an_archived_connection_shows_both_states_accurately(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-STATE-3', null);
        $service->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('archived', $row['status']);
                $this->assertSame('archived', $row['connection_state']);
            });
    }

    #[Test]
    public function restoring_the_outlet_alone_results_in_active_outlet_and_archived_connection(): void
    {
        $admin = $this->adminWith(['manage_pos_connections', 'view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-STATE-4', null);
        $service->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.restore', $outlet->fresh()))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('active', $row['status']);
                $this->assertSame('archived', $row['connection_state'],
                    'Restore Outlet must never resume or otherwise change the connection.');
            });
    }

    #[Test]
    public function restoring_the_connection_after_the_outlet_results_in_active_outlet_and_paused_connection(): void
    {
        $admin = $this->adminWith(['manage_pos_connections', 'view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();
        $service = app(PosConnectionProvisioningService::class);
        $connection = $service->createSandboxConnection($outlet, 'REST-STATE-5', null);
        $service->archiveConnection($connection->fresh());
        app(RestaurantOutletService::class)->archiveOutlet($outlet->fresh());
        app(RestaurantOutletService::class)->restoreOutlet($outlet->fresh());

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.restore', $connection->fresh()))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertSame('active', $row['status']);
                $this->assertSame('paused', $row['connection_state']);
            });
    }

    // ══ Gate 5 of the six-gate Petpooja live activation invariant ═══════

    /**
     * ⚠️ THE MOST LIKELY EXPLANATION FOR "the button is missing in a real
     * environment": `authorize_pos_outlets` is defined in PermissionSeeder,
     * but a Super Admin's actual permission SET comes from the ROLE's
     * synced permissions (RoleSeeder's `$superAdmin->permissions()->sync(...)`),
     * not from the seeder file existing. A deploy that adds this permission
     * to PermissionSeeder but never re-runs PermissionSeeder THEN RoleSeeder
     * against that environment's database leaves every existing Super Admin
     * role exactly as it was — permission defined, never granted, so the
     * button's (correct) `permissions.includes('authorize_pos_outlets')`
     * check legitimately renders nothing.
     *
     * This runs the REAL seeders, in the REAL documented order
     * (DatabaseSeeder::class lists PermissionSeeder before RoleSeeder), to
     * prove the wiring itself is correct — if this ever fails, the seeder
     * class or its ordering is broken, not just "someone forgot to deploy".
     */
    #[Test]
    public function seeding_permissions_then_roles_grants_super_admin_the_outlet_authorization_permission(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $superAdmin = Role::where('key', Role::KEY_SUPER_ADMIN)->firstOrFail();
        $this->assertTrue(
            $superAdmin->permissions()->where('key', 'authorize_pos_outlets')->exists(),
            'The Super Admin role must hold authorize_pos_outlets once PermissionSeeder and RoleSeeder have both run — '
            .'if this fails, a real Super Admin in any environment where these seeders ran (in this order) will not '
            .'see the Authorize for Live POS action.'
        );

        // End-to-end: an admin actually wearing that real (not ad hoc test)
        // role sees the permission in the same auth.permissions prop the
        // Outlets page's `canAuthorizeForLivePos` check reads.
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $admin->roles()->syncWithoutDetaching([$superAdmin->id]);

        $this->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.permissions', fn (Collection $perms) => $perms->contains('authorize_pos_outlets')));
    }

    #[Test]
    public function the_outlets_index_reports_live_pos_authorization_state(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertFalse($row['authorized_for_live_pos']);
            });

        app(RestaurantOutletService::class)->authorizeForLivePos($outlet);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.restaurant.outlets.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($outlet) {
                $row = $this->findOutletRow($page->toArray(), $outlet->uuid);
                $this->assertTrue($row['authorized_for_live_pos']);
            });
    }

    #[Test]
    public function authorizing_an_outlet_for_live_pos_through_its_route_persists_and_audits(): void
    {
        $admin = $this->adminWith(['authorize_pos_outlets']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.outlets.authorize-live-pos', $outlet))
            ->assertSessionHasNoErrors();

        $this->assertTrue($outlet->fresh()->isAuthorizedForLivePos());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restaurant.outlet.pos_live_authorized',
            'auditable_id' => $outlet->id,
            'actor_admin_id' => $admin->id,
        ]);
    }

    /**
     * The positive control's mirror: `manage_pos_connections` alone — the
     * permission that already covers day-to-day outlet CRUD — must NOT be
     * enough for this more consequential act, the same split this module
     * already applies to token rotation and activation.
     */
    #[Test]
    public function admin_with_only_manage_pos_connections_cannot_authorize_an_outlet_for_live_pos(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.restaurant.outlets.authorize-live-pos', $outlet))
            ->assertForbidden();

        $this->assertFalse($outlet->fresh()->isAuthorizedForLivePos());
    }
}
