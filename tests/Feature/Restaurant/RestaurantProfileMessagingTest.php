<?php

namespace Tests\Feature\Restaurant;

use App\Events\ContactCreated;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantProfileMessagingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_updates_only_its_brand_outlet_and_feedback_configuration_with_safe_audits(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->selection($workspace);

        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.profile.update'), [
            'brand_name' => 'North Kitchen', 'legal_business_name' => 'North Kitchen Foods LLP',
            'registered_business_address' => '1 Market Street', 'website' => 'https://north.example',
            'social_links' => ['google' => 'https://g.page/north'],
        ])->assertRedirect();
        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.update', $outlet->uuid), [
            'name' => 'North Downtown', 'address' => '2 Outlet Road', 'public_phone' => '+919000000000',
            'public_email' => 'outlet@north.example', 'public_website' => 'https://downtown.north.example',
            'gstin' => '27ABCDE1234F1Z5', 'fssai_number' => '12345678901234',
        ])->assertRedirect();
        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), [
            'whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id,
            'timing_preference' => 'next_day', 'next_day_at' => '10:30', 'google_review_url' => 'https://g.page/north/review',
        ])->assertRedirect();

        $outlet->refresh();
        $this->assertSame('North Downtown', $outlet->name);
        $this->assertSame('27ABCDE1234F1Z5', $outlet->gstin);
        $this->assertSame('12345678901234', $outlet->fssai_number);
        $config = RestaurantFeedbackDeliveryConfig::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $this->assertSame($workspace->id, $config->workspace_id);
        $this->assertSame($selection['sender']->id, $config->whatsapp_phone_number_id);
        $this->assertSame('next_day', $config->timing_preference);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.brand_profile.updated', 'user_id' => $owner->id, 'workspace_id' => $workspace->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.outlet.profile_updated', 'user_id' => $owner->id, 'auditable_id' => $outlet->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.outlet.feedback_delivery_config_updated', 'user_id' => $owner->id, 'auditable_id' => $config->id]);
        $audit = AuditLog::query()->where('action', 'restaurant.outlet.feedback_delivery_config_updated')->firstOrFail();
        $this->assertArrayNotHasKey('access_token', $audit->meta ?? []);
    }

    #[Test]
    public function staff_is_forbidden_and_a_foreign_outlet_is_not_found(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();
        $foreign = RestaurantOutlet::factory()->create(['workspace_id' => $other->id]);
        $staff = User::factory()->create(['client_id' => $workspace->client_id, 'workspace_id' => $workspace->id, 'role' => User::ROLE_CLIENT, 'client_role' => User::CLIENT_ROLE_STAFF, 'status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $workspace->members()->syncWithoutDetaching([$staff->id => ['role' => 'member']]);

        $this->actingAs($staff)->getJson(route('client.restaurant.profile-messaging.index'))->assertForbidden();
        $this->actingAs($owner)->putJson(route('client.restaurant.profile-messaging.outlets.messaging.update', $foreign->uuid), ['digital_bill_enabled' => true, 'feedback_request_enabled' => true])->assertNotFound();
    }

    #[Test]
    public function invalid_feedback_selection_or_outlet_identifiers_are_rejected_without_writes(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->selection($workspace);
        $foreignTemplate = WhatsappTemplate::query()->create(['workspace_id' => $workspace->id, 'waba_id' => 'wrong-waba', 'name' => 'wrong', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED']);

        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), ['whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $foreignTemplate->id, 'timing_preference' => 'next_day', 'next_day_at' => '09:00'])->assertSessionHasErrors('whatsapp_template_id');
        $this->assertDatabaseMissing('restaurant_feedback_delivery_configs', ['outlet_id' => $outlet->id]);
    }

    #[Test]
    public function feedback_configuration_rejects_foreign_inactive_and_unapproved_selections(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->selection($workspace);
        $foreign = $this->selection($other);

        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), [
            'whatsapp_phone_number_id' => $foreign['sender']->id, 'whatsapp_template_id' => $selection['template']->id,
            'timing_preference' => 'immediately',
        ])->assertSessionHasErrors('whatsapp_phone_number_id');

        $selection['sender']->businessAccount->update(['status' => 'inactive']);
        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), [
            'whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id,
            'timing_preference' => 'immediately',
        ])->assertSessionHasErrors('whatsapp_phone_number_id');

        $selection['sender']->businessAccount->update(['status' => 'active']);
        $selection['template']->update(['status' => 'PENDING']);
        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), [
            'whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id,
            'timing_preference' => 'immediately',
        ])->assertSessionHasErrors('whatsapp_template_id');

        $this->assertDatabaseMissing('restaurant_feedback_delivery_configs', ['outlet_id' => $outlet->id]);
    }

    #[Test]
    public function an_authorized_admin_can_save_feedback_configuration_and_a_missing_permission_is_forbidden(): void
    {
        $admin = $this->adminWith(['manage_pos_connections']);
        $denied = $this->adminWith(['view_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->selection($workspace);
        $payload = ['whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id, 'timing_preference' => 'one_hour'];

        $this->actingAs($denied, 'admin')->putJson(route('admin.restaurant.outlets.feedback-delivery-config.update', $outlet), $payload)->assertForbidden();
        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.outlets.feedback-delivery-config.update', $outlet), $payload)->assertRedirect();

        $config = RestaurantFeedbackDeliveryConfig::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $this->assertSame($selection['sender']->id, $config->whatsapp_phone_number_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.outlet.feedback_delivery_config_updated', 'actor_admin_id' => $admin->id, 'workspace_id' => $workspace->id, 'auditable_id' => $config->id]);
    }

    #[Test]
    public function consolidated_settings_dispatch_no_outbound_work(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $selection = $this->selection($workspace);
        Queue::fake();
        Bus::fake();
        Event::fake([ContactCreated::class]);
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.messaging.update', $outlet->uuid), ['digital_bill_enabled' => true, 'feedback_request_enabled' => false])->assertRedirect();
        $this->actingAs($owner)->put(route('client.restaurant.profile-messaging.outlets.feedback.update', $outlet->uuid), [
            'whatsapp_phone_number_id' => $selection['sender']->id, 'whatsapp_template_id' => $selection['template']->id,
            'timing_preference' => 'immediately',
        ])->assertRedirect();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(ContactCreated::class);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    /** @return array{sender:WhatsappPhoneNumber,template:WhatsappTemplate} */
    private function selection(Workspace $workspace): array
    {
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id, 'credentials' => ['system_user_token' => 'token'], 'status' => 'active']);
        $sender = WhatsappPhoneNumber::query()->create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'feedback-'.uniqid()]);
        $template = WhatsappTemplate::query()->create(['workspace_id' => $workspace->id, 'waba_id' => $waba->waba_id, 'name' => 'feedback-'.uniqid(), 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED']);

        return compact('sender', 'template');
    }

    /** @param list<string> $permissionKeys */
    private function adminWith(array $permissionKeys): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_PROFILE_'.uniqid(), 'name' => 'Profile', 'description' => 'test']);
        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $admin->roles()->attach($role);

        return $admin;
    }
}
