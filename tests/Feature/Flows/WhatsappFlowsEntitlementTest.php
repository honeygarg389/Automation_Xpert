<?php

namespace Tests\Feature\Flows;

use App\Models\Currency;
use App\Models\Permission;
use App\Models\Plan;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Flows\Http\Middleware\EnsureFlowsEnabled;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappFlowsEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** @return array{user: \App\Models\User, workspace: \App\Models\Workspace, plan: Plan} */
    private function customerOnFlowsPlan(bool $enabled): array
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create([
            'whatsapp_flows_enabled' => $enabled,
            'currency_code' => 'USD',
            'monthly_price_cents' => 1200,
        ]);
        $this->attachPlanToClient($client, $plan);

        return compact('user', 'workspace', 'plan');
    }

    #[Test]
    public function a_workspace_without_the_flows_entitlement_is_hidden_and_forbidden(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->customerOnFlowsPlan(false);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('client.flows.index'))
            ->assertForbidden();

        $props = $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get('/app/dashboard')->getOriginalContent()->getData()['page']['props'] ?? [];
        $this->assertFalse(data_get($props, 'features.whatsapp_flows'),
            'The sidebar presentation flag must agree with the route protection.');
    }

    #[Test]
    public function a_workspace_with_the_flows_entitlement_can_access_the_module_and_sees_the_feature_flag(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->customerOnFlowsPlan(true);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('client.flows.index'))
            ->assertOk();

        $props = $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get('/app/dashboard')->getOriginalContent()->getData()['page']['props'] ?? [];
        $this->assertTrue(data_get($props, 'features.whatsapp_flows'));
    }

    #[Test]
    public function an_admin_plan_toggle_changes_the_resolved_entitlement_and_unblocks_the_route(): void
    {
        Currency::create(['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true]);
        ['user' => $user, 'workspace' => $workspace, 'plan' => $plan] = $this->customerOnFlowsPlan(false);

        // Warm the materialized entitlement first: this proves the plan update
        // refreshes it, rather than passing only because no cached answer exists.
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('client.flows.index'))
            ->assertForbidden();

        $admin = $this->createSuperAdmin();
        $updatePlans = Permission::firstOrCreate(
            ['key' => 'update_plans'],
            ['name' => 'Update Plans', 'category' => 'Plans']
        );
        $admin->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$updatePlans->id]);

        $this->actingAs($admin, 'admin')->put(route('admin.plans.update', $plan), [
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => null,
            'currency_code' => 'USD',
            'monthly_price_cents' => 1200,
            'quarterly_price_cents' => null,
            'half_yearly_price_cents' => null,
            'yearly_price_cents' => null,
            'trial_days' => 0,
            'features' => [],
            'limits' => [],
            'enabled' => true,
            'featured' => false,
            'popular' => false,
            'sort_order' => 0,
            'white_label_enabled' => false,
            'whatsapp_flows_enabled' => true,
        ])->assertRedirect(route('admin.plans.index'));

        $this->assertTrue($plan->fresh()->whatsapp_flows_enabled);
        $this->assertTrue(app(Entitlements::class)->forWorkspace($workspace->id)->allows(EnsureFlowsEnabled::KEY));

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('client.flows.index'))
            ->assertOk();

        $this->actingAs($admin, 'admin')->get(route('admin.plans.index'))
            ->assertInertia(fn ($page) => $page->where('plans.0.whatsapp_flows_enabled', true));
    }

    #[Test]
    public function the_new_plan_default_preserves_the_pre_gate_flows_access(): void
    {
        $plan = Plan::factory()->create()->fresh();

        $this->assertTrue($plan->fresh()->whatsapp_flows_enabled);
        $this->assertTrue(app(\App\Modules\Entitlements\Services\PlanPackageSynthesizer::class)
            ->forPlan($plan)->flags[EnsureFlowsEnabled::KEY] ?? false);
    }
}
