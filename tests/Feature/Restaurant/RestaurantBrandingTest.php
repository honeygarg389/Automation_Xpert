<?php

namespace Tests\Feature\Restaurant;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantBrandingTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $permissionKeys */
    private function adminWith(array $permissionKeys = ['manage_pos_connections']): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'TEST_BRANDING_'.uniqid(), 'name' => 'Branding', 'description' => 'test']);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'test']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->attach($role);

        return $admin;
    }

    #[Test]
    public function an_authorized_admin_updates_workspace_branding_and_outlet_public_contact_with_audit_rows(): void
    {
        Storage::fake('public');
        $admin = $this->adminWith();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.branding.update', $workspace), [
            'brand_name' => 'North Kitchen', 'primary_color' => '#124578', 'thank_you_note' => 'Visit again.',
            'social_links' => ['instagram' => 'https://instagram.com/northkitchen'],
            'logo' => UploadedFile::fake()->image('logo.png', 80, 80),
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->put(route('admin.restaurant.branding.outlets.update', [$workspace, $outlet]), ['public_phone' => '+919000000000', 'public_website' => 'https://north.example'])->assertRedirect();

        $profile = RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('North Kitchen', $profile->brand_name);
        $this->assertSame('#124578', $profile->primary_color);
        $this->assertNotNull($profile->logo_path);
        $this->assertSame('+919000000000', $outlet->fresh()->public_phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.brand_profile.updated', 'actor_admin_id' => $admin->id, 'workspace_id' => $workspace->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.outlet.public_contact_updated', 'actor_admin_id' => $admin->id, 'auditable_id' => $outlet->id]);
    }

    #[Test]
    public function an_owner_can_manage_only_the_current_workspace_brand_and_outlets_without_outbound_side_effects(): void
    {
        ['user' => $owner, 'workspace' => $workspace] = $this->createWorkspaceContext();
        ['workspace' => $other] = $this->createWorkspaceContext();
        $ownOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $workspace->id]);
        $otherOutlet = RestaurantOutlet::factory()->create(['workspace_id' => $other->id]);
        Queue::fake();
        Bus::fake();
        Http::fake();
        Mail::fake();
        Notification::fake();

        $this->actingAs($owner)->get(route('client.restaurant.branding.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($ownOutlet, $otherOutlet) {
                $outletUuids = array_column($page->toArray()['props']['outlets'], 'uuid');
                $this->assertSame([$ownOutlet->uuid], $outletUuids);
                $this->assertNotContains($otherOutlet->uuid, $outletUuids);
            });

        $this->actingAs($owner)->put(route('client.restaurant.branding.update'), ['brand_name' => 'Own brand', 'social_links' => ['facebook' => 'https://facebook.com/own']])->assertRedirect();
        $this->actingAs($owner)->put(route('client.restaurant.branding.outlets.update', $ownOutlet), ['public_phone' => '+919111111111', 'public_website' => 'https://own.example'])->assertRedirect();
        $this->actingAs($owner)->putJson(route('client.restaurant.branding.outlets.update', $otherOutlet), ['public_phone' => '+919222222222', 'public_website' => 'https://other.example'])->assertNotFound();

        $this->assertSame('Own brand', RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->value('brand_name'));
        $this->assertNull(RestaurantBrandProfile::query()->where('workspace_id', $other->id)->value('brand_name'));
        $this->assertSame('+919111111111', $ownOutlet->fresh()->public_phone);
        $this->assertNull($otherOutlet->fresh()->public_phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'restaurant.brand_profile.updated', 'user_id' => $owner->id, 'workspace_id' => $workspace->id]);
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function branding_input_rejects_unsafe_color_social_url_and_non_image_upload(): void
    {
        ['user' => $owner] = $this->createWorkspaceContext();

        $this->actingAs($owner)->put(route('client.restaurant.branding.update'), [
            'primary_color' => 'red', 'social_links' => ['instagram' => 'javascript:alert(1)'],
            'logo' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors(['primary_color', 'social_links.instagram', 'logo']);
    }

    #[Test]
    public function admins_without_the_outlet_management_permission_cannot_update_branding(): void
    {
        $admin = $this->adminWith(['view_pos_connections']);
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($admin, 'admin')->putJson(route('admin.restaurant.branding.update', $workspace), [
            'brand_name' => 'Unauthorised change',
        ])->assertForbidden();

        $this->assertNull(RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->value('brand_name'));
    }
}
