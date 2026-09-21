<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantOutboundPolicy;
use App\Modules\Restaurant\Support\RestaurantOutboundPurpose;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantDigitalBillDeliveryConfigTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_create_and_replace_one_outlets_valid_local_configuration_with_a_safe_audit(): void
    {
        $admin = $this->adminWithManagePermission();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $first = $this->eligibleSelections($workspace);
        $second = $this->eligibleSelections($workspace);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($first))->assertRedirect();
        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($second))->assertRedirect();

        $this->assertSame(1, RestaurantDigitalBillDeliveryConfig::query()->where('outlet_id', $outlet->id)->count());
        $config = RestaurantDigitalBillDeliveryConfig::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $this->assertSame($workspace->id, $config->workspace_id);
        $this->assertSame($second['sender']->id, $config->whatsapp_phone_number_id);
        $this->assertSame($second['template']->id, $config->whatsapp_template_id);
        $audit = AuditLog::query()->where('action', 'restaurant.outlet.digital_bill_delivery_config_updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_admin_id);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertSame($first['sender']->id, $audit->old_values['whatsapp_phone_number_id']);
        $this->assertSame($first['template']->id, $audit->old_values['whatsapp_template_id']);
        $this->assertSame($second['sender']->id, $audit->new_values['whatsapp_phone_number_id']);
        $this->assertSame($second['template']->id, $audit->new_values['whatsapp_template_id']);
        $this->assertArrayNotHasKey('access_token', $audit->meta ?? []);
    }

    #[Test]
    public function client_owner_can_only_write_the_active_workspaces_outlet_and_staff_is_forbidden(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->eligibleSelections($workspace);
        ['workspace' => $other] = $this->createWorkspaceContext();
        $foreignOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $other->id]);

        $this->actingAs($owner)->put(route('client.restaurant.messaging.outlets.digital-bill-delivery-config.update', $outlet), array_merge($this->payload($selection), ['workspace_id' => $other->id]))->assertRedirect();
        $this->assertDatabaseHas('restaurant_digital_bill_delivery_configs', ['outlet_id' => $outlet->id, 'workspace_id' => $workspace->id]);
        $this->actingAs($owner)->putJson(route('client.restaurant.messaging.outlets.digital-bill-delivery-config.update', $foreignOutlet), $this->payload($selection))->assertNotFound();

        $staff = User::factory()->create([
            'client_id' => $workspace->client_id,
            'workspace_id' => $workspace->id,
            'role' => User::ROLE_CLIENT,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $workspace->members()->syncWithoutDetaching([$staff->id => ['role' => 'member']]);
        $this->actingAs($staff)->putJson(route('client.restaurant.messaging.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($selection))->assertForbidden();
    }

    #[Test]
    public function admin_without_manage_permission_is_forbidden_and_cannot_create_configuration(): void
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->eligibleSelections($workspace);

        $this->actingAs($admin, 'admin')
            ->putJson(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($selection))
            ->assertForbidden();

        $this->assertDatabaseMissing('restaurant_digital_bill_delivery_configs', ['outlet_id' => $outlet->id]);
    }

    #[Test]
    public function invalid_foreign_inactive_and_non_utility_selections_are_rejected_without_a_partial_config(): void
    {
        $admin = $this->adminWithManagePermission();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $valid = $this->eligibleSelections($workspace);
        $marketing = WhatsappTemplate::query()->create(array_merge($valid['template']->only(['workspace_id', 'waba_id', 'language']), ['name' => 'marketing', 'status' => 'APPROVED', 'category' => 'MARKETING']));
        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), ['whatsapp_phone_number_id' => $valid['sender']->id, 'whatsapp_template_id' => $marketing->id])->assertSessionHasErrors('whatsapp_template_id');
        $valid['sender']->businessAccount->update(['status' => 'inactive']);
        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($valid))->assertSessionHasErrors('whatsapp_phone_number_id');
        $this->assertDatabaseMissing('restaurant_digital_bill_delivery_configs', ['outlet_id' => $outlet->id]);
    }

    #[Test]
    public function deleting_a_selected_sender_leaves_the_config_incomplete_and_policy_fails_closed(): void
    {
        $records = $this->policyRecords();
        WorkspaceContext::for($records['workspace']->id, function () use ($records): void {
            RestaurantDigitalBillDeliveryConfig::create(['workspace_id' => $records['workspace']->id, 'outlet_id' => $records['outlet']->id, 'whatsapp_phone_number_id' => $records['sender']->id, 'whatsapp_template_id' => $records['template']->id]);
        });
        $records['sender']->delete();
        $config = WorkspaceContext::for($records['workspace']->id, fn () => RestaurantDigitalBillDeliveryConfig::query()->firstOrFail());
        $this->assertNull($config->whatsapp_phone_number_id);
        $decision = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantOutboundPolicy::class)->evaluate(RestaurantOutboundPurpose::DIGITAL_BILL, $records['bill']->id, 'deleted', $records['template']->id));
        $this->assertFalse($decision->allowed);
        $this->assertSame(RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY, $decision->reasonCode);
    }

    #[Test]
    public function policy_rejects_an_approved_marketing_template_for_digital_bill(): void
    {
        $records = $this->policyRecords();
        $records['template']->update(['category' => 'MARKETING']);
        $decision = WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantOutboundPolicy::class)->evaluate(RestaurantOutboundPurpose::DIGITAL_BILL, $records['bill']->id, $records['sender']->phone_number_id, $records['template']->id));
        $this->assertFalse($decision->allowed);
        $this->assertSame(RestaurantOutboundPolicy::REASON_TEMPLATE_NOT_UTILITY, $decision->reasonCode);
    }

    #[Test]
    public function saving_configuration_dispatches_no_outbound_work(): void
    {
        $admin = $this->adminWithManagePermission();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->eligibleSelections($workspace);
        Queue::fake();
        Bus::fake();
        Event::fake([ContactCreated::class]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', $outlet), $this->payload($selection))->assertRedirect();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(ContactCreated::class);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    /** @return array{sender: WhatsappPhoneNumber, template: WhatsappTemplate} */
    private function eligibleSelections(Workspace $workspace): array
    {
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id, 'credentials' => ['system_user_token' => 'token'], 'status' => 'active']);
        $sender = WhatsappPhoneNumber::query()->create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'phone-'.uniqid()]);
        $template = WhatsappTemplate::query()->create(['workspace_id' => $workspace->id, 'waba_id' => $waba->waba_id, 'name' => 'utility-'.uniqid(), 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED']);

        return compact('sender', 'template');
    }

    /** @param array{sender: WhatsappPhoneNumber, template: WhatsappTemplate} $selection @return array{whatsapp_phone_number_id:int, whatsapp_template_id:int} */
    private function payload(array $selection): array
    {
        return ['whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id];
    }

    private function adminWithManagePermission(): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_DIGITAL_BILL_CONFIG_'.uniqid(), 'name' => 'Test', 'description' => 'Test']);
        $permission = Permission::firstOrCreate(['key' => 'manage_pos_connections'], ['name' => 'manage_pos_connections', 'category' => 'test']);
        $role->permissions()->attach($permission);
        $admin->roles()->attach($role);

        return $admin->fresh();
    }

    /** @return array{workspace:Workspace,outlet:RestaurantOutlet,bill:RestaurantBill,sender:WhatsappPhoneNumber,template:WhatsappTemplate} */
    private function policyRecords(): array
    {
        $workspace = Workspace::factory()->create();

        return WorkspaceContext::for($workspace->id, function () use ($workspace): array {
            $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id, 'digital_bill_enabled' => true]);
            $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+919876543210']);
            $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id]);
            $bill = RestaurantBill::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id, 'contact_id' => $contact->id, 'connection_id' => $connection->id, 'source_order_status' => 'Success']);

            return compact('workspace', 'outlet', 'bill') + $this->eligibleSelections($workspace);
        });
    }
}
