<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantOutletMessagingSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $permissionKeys */
    private function adminWith(array $permissionKeys): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_RESTAURANT_MESSAGING_'.uniqid(), 'name' => 'Test role', 'description' => 'test']);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    #[Test]
    public function every_outlet_creation_path_starts_with_both_messaging_settings_off(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $factoryOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $serviceOutlet = app(RestaurantOutletService::class)->createOutlet($workspace, 'Service outlet', null, null);
        $admin = $this->adminWith(['manage_pos_connections']);
        $this->actingAs($admin, 'admin')->post(route('admin.restaurant.connections.store'), [
            'environment' => 'sandbox',
            'mode' => 'new',
            'workspace_id' => $workspace->id,
            'new_outlet_name' => 'Connection flow outlet',
            'external_ref' => 'MSG-SETTINGS-DEFAULTS',
        ])->assertSessionHasNoErrors();
        $connectionOutlet = RestaurantOutlet::query()->where('name', 'Connection flow outlet')->firstOrFail();

        foreach ([$factoryOutlet, $serviceOutlet, $connectionOutlet] as $outlet) {
            $outlet->refresh();
            $this->assertFalse($outlet->digital_bill_enabled);
            $this->assertFalse($outlet->feedback_request_enabled);
        }
    }

    #[Test]
    public function an_admin_with_manage_pos_connections_updates_the_two_settings_independently_and_is_audited(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.messaging-settings.update', $outlet), [
            'digital_bill_enabled' => true,
            'feedback_request_enabled' => false,
        ])->assertRedirect()->assertSessionHas('success');

        $outlet->refresh();
        $this->assertTrue($outlet->digital_bill_enabled);
        $this->assertFalse($outlet->feedback_request_enabled);

        $audit = AuditLog::query()->where('action', 'restaurant.outlet.messaging_settings_updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_admin_id);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertSame($outlet->id, $audit->auditable_id);
        $this->assertSame(['digital_bill_enabled' => false, 'feedback_request_enabled' => false], $audit->old_values);
        $this->assertSame(['digital_bill_enabled' => true, 'feedback_request_enabled' => false], $audit->new_values);
    }

    #[Test]
    public function an_admin_without_manage_pos_connections_is_forbidden_and_cannot_change_settings(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create();

        $this->actingAs($admin, 'admin')->putJson(route('admin.restaurant.outlets.messaging-settings.update', $outlet), [
            'digital_bill_enabled' => true,
            'feedback_request_enabled' => true,
        ])->assertForbidden();

        $this->assertFalse($outlet->fresh()->digital_bill_enabled);
        $this->assertFalse($outlet->fresh()->feedback_request_enabled);
    }

    #[Test]
    public function a_workspace_administrator_can_list_and_update_only_the_current_workspaces_outlets(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $ownOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Own outlet']);
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $otherOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Other outlet']);

        $this->actingAs($owner)->get(route('client.restaurant.messaging.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($ownOutlet, $otherOutlet) {
                $rows = $page->toArray()['props']['outlets'];
                $this->assertSame([$ownOutlet->uuid], array_column($rows, 'uuid'));
                $this->assertNotContains($otherOutlet->uuid, array_column($rows, 'uuid'));
            });

        $this->actingAs($owner)->put(route('client.restaurant.messaging.outlets.update', $ownOutlet), [
            'digital_bill_enabled' => false,
            'feedback_request_enabled' => true,
            'workspace_id' => $otherWorkspace->id,
        ])->assertRedirect();

        $this->assertFalse($ownOutlet->fresh()->digital_bill_enabled);
        $this->assertTrue($ownOutlet->fresh()->feedback_request_enabled);
        $this->assertFalse($otherOutlet->fresh()->digital_bill_enabled);
        $this->assertFalse($otherOutlet->fresh()->feedback_request_enabled);

        $ownerAudit = AuditLog::query()->where('action', 'restaurant.outlet.messaging_settings_updated')->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $ownerAudit->user_id);
        $this->assertSame($workspace->id, $ownerAudit->workspace_id);
        $this->assertSame($ownOutlet->id, $ownerAudit->auditable_id);
        $this->assertSame(['digital_bill_enabled' => false, 'feedback_request_enabled' => false], $ownerAudit->old_values);
        $this->assertSame(['digital_bill_enabled' => false, 'feedback_request_enabled' => true], $ownerAudit->new_values);

        $this->actingAs($owner)->putJson(route('client.restaurant.messaging.outlets.update', $otherOutlet), [
            'digital_bill_enabled' => true,
            'feedback_request_enabled' => true,
        ])->assertNotFound();
    }

    #[Test]
    public function client_staff_cannot_read_or_update_restaurant_messaging_settings(): void
    {
        ['user' => $staff, 'workspace' => $workspace] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_STAFF]);
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($staff)->get(route('client.restaurant.messaging.index'))->assertForbidden();
        $this->actingAs($staff)->putJson(route('client.restaurant.messaging.outlets.update', $outlet), [
            'digital_bill_enabled' => true,
            'feedback_request_enabled' => true,
        ])->assertForbidden();
    }

    #[Test]
    public function a_workspace_owner_sees_and_updates_the_active_switched_workspace_not_their_home_workspace(): void
    {
        ['user' => $owner, 'home' => $home, 'other' => $activeWorkspace] = $this->createTwoWorkspaceUser();
        $homeOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $home->id, 'name' => 'Home outlet']);
        $activeOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $activeWorkspace->id, 'name' => 'Active outlet']);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $activeWorkspace->id])
            ->get(route('client.restaurant.messaging.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($homeOutlet, $activeOutlet) {
                $uuids = array_column($page->toArray()['props']['outlets'], 'uuid');
                $this->assertSame([$activeOutlet->uuid], $uuids);
                $this->assertNotContains($homeOutlet->uuid, $uuids);
            });

        $this->actingAs($owner)->withSession(['current_workspace_id' => $activeWorkspace->id])
            ->put(route('client.restaurant.messaging.outlets.update', $activeOutlet), [
                'digital_bill_enabled' => true,
                'feedback_request_enabled' => false,
            ])->assertRedirect();

        $this->assertTrue($activeOutlet->fresh()->digital_bill_enabled);
        $this->assertFalse($homeOutlet->fresh()->digital_bill_enabled);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $activeWorkspace->id])
            ->putJson(route('client.restaurant.messaging.outlets.update', $homeOutlet), [
                'digital_bill_enabled' => true,
                'feedback_request_enabled' => true,
            ])->assertNotFound();
    }

    #[Test]
    public function missing_or_invalid_messaging_setting_values_are_rejected_without_partial_changes(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outlet = RestaurantOutlet::factory()->create(['digital_bill_enabled' => true]);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.messaging-settings.update', $outlet), [
            'digital_bill_enabled' => 'not-a-boolean',
        ])->assertSessionHasErrors(['digital_bill_enabled', 'feedback_request_enabled']);

        $this->assertTrue($outlet->fresh()->digital_bill_enabled);
        $this->assertFalse($outlet->fresh()->feedback_request_enabled);
    }

    #[Test]
    public function updating_one_outlet_never_changes_another_and_triggers_no_outbound_work(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $outletA = RestaurantOutlet::factory()->create();
        $outletB = RestaurantOutlet::factory()->create();
        $beforeCustomerTables = collect(['messages', 'conversations', 'automation_runs', 'campaign_recipients', 'webhook_deliveries', 'pos_webhook_events', 'restaurant_bills'])
            ->mapWithKeys(fn (string $table): array => [$table => \DB::table($table)->count()]);

        Queue::fake();
        Bus::fake();
        Event::fake([ContactCreated::class]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.messaging-settings.update', $outletA), [
            'digital_bill_enabled' => true,
            'feedback_request_enabled' => true,
        ])->assertRedirect();

        $this->assertTrue($outletA->fresh()->digital_bill_enabled);
        $this->assertTrue($outletA->fresh()->feedback_request_enabled);
        $this->assertFalse($outletB->fresh()->digital_bill_enabled);
        $this->assertFalse($outletB->fresh()->feedback_request_enabled);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(ContactCreated::class);
        foreach ($beforeCustomerTables as $table => $count) {
            $this->assertSame($count, \DB::table($table)->count(), "{$table} must not change when settings are saved.");
        }
    }
}
