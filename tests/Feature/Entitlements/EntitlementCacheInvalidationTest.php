<?php

namespace Tests\Feature\Entitlements;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Entitlements\Jobs\ReconcileWorkspaceEntitlements;
use App\Modules\Entitlements\Services\EntitlementCache;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Flows\Http\Middleware\EnsureFlowsEnabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cache is persistent, while the admin writes are synchronous HTTP
 * requests. These tests deliberately fake the queue after warming a row: an
 * answer that changes here proves the request itself deleted stale state rather
 * than merely depending on a worker that happens to run in tests.
 */
class EntitlementCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['entitlements.cache_enabled' => true]);
        Currency::create([
            'code' => 'USD',
            'symbol' => '$',
            'decimals' => 2,
            'exchange_rate' => 1,
            'is_default' => true,
            'enabled' => true,
        ]);
    }

    /** @return array{client: Client, workspace: Workspace, plan: Plan} */
    private function workspaceOnPlan(bool $flowsEnabled, array $limits = []): array
    {
        ['client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create([
            'currency_code' => 'USD',
            'limits' => $limits,
            'whatsapp_flows_enabled' => $flowsEnabled,
        ]);
        $this->attachPlanToClient($client, $plan);

        return compact('client', 'workspace', 'plan');
    }

    private function adminWith(string $permission): AdminUser
    {
        $admin = $this->createSuperAdmin();
        $grant = Permission::firstOrCreate(
            ['key' => $permission],
            ['name' => str_replace('_', ' ', $permission), 'category' => 'test'],
        );

        $admin->roles()->firstOrFail()->permissions()->syncWithoutDetaching([$grant->id]);

        return $admin->fresh();
    }

    /** @return array<string, mixed> */
    private function planUpdatePayload(Plan $plan, bool $flowsEnabled): array
    {
        return [
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'currency_code' => 'USD',
            'monthly_price_cents' => $plan->monthly_price_cents ?? 1200,
            'quarterly_price_cents' => $plan->quarterly_price_cents,
            'half_yearly_price_cents' => $plan->half_yearly_price_cents,
            'yearly_price_cents' => $plan->yearly_price_cents,
            'trial_days' => $plan->trial_days ?? 0,
            'features' => $plan->features ?? [],
            'limits' => $plan->limits ?? [],
            'enabled' => (bool) $plan->enabled,
            'featured' => (bool) $plan->featured,
            'popular' => (bool) $plan->popular,
            'sort_order' => $plan->sort_order,
            'white_label_enabled' => (bool) $plan->white_label_enabled,
            'whatsapp_flows_enabled' => $flowsEnabled,
        ];
    }

    private function cacheRow(int $workspaceId): ?object
    {
        return DB::table('workspace_entitlements')->where('workspace_id', $workspaceId)->first();
    }

    #[Test]
    public function editing_a_plan_invalidates_warmed_workspace_rows_before_the_queue_runs(): void
    {
        ['workspace' => $workspace, 'plan' => $plan] = $this->workspaceOnPlan(false);

        $this->assertFalse(app(Entitlements::class)->forWorkspace($workspace->id)->allows(EnsureFlowsEnabled::KEY));
        $this->assertNotNull($this->cacheRow($workspace->id), 'The test must start with a materialized old answer.');

        Queue::fake();

        $this->actingAs($this->adminWith('update_plans'), 'admin')
            ->put(route('admin.plans.update', $plan), $this->planUpdatePayload($plan, true))
            ->assertRedirect(route('admin.plans.index'));

        Queue::assertPushed(
            ReconcileWorkspaceEntitlements::class,
            fn (ReconcileWorkspaceEntitlements $job) => $job->clientId === $workspace->client_id,
        );
        $this->assertNull($this->cacheRow($workspace->id),
            'The request left its old entitlement row in place and therefore still depends on a queue worker.');

        app(EntitlementResolver::class)->flush();
        $this->assertTrue(app(Entitlements::class)->forWorkspace($workspace->id)->allows(EnsureFlowsEnabled::KEY),
            'The next read did not rebuild from the plan just saved.');
    }

    #[Test]
    public function reassigning_a_client_plan_invalidates_warmed_workspace_rows_before_the_queue_runs(): void
    {
        ['client' => $client, 'workspace' => $workspace] = $this->workspaceOnPlan(false);
        $newPlan = Plan::factory()->create([
            'currency_code' => 'USD',
            'limits' => [],
            'whatsapp_flows_enabled' => true,
        ]);

        $this->assertFalse(app(Entitlements::class)->forWorkspace($workspace->id)->allows(EnsureFlowsEnabled::KEY));
        $this->assertNotNull($this->cacheRow($workspace->id), 'The test must start with a materialized old answer.');

        Queue::fake();

        $this->actingAs($this->adminWith('manage_subscriptions'), 'admin')
            ->postJson(route('admin.clients.assign-plan', $client), [
                'plan_id' => $newPlan->id,
                'billing_cycle' => 'monthly',
            ])
            ->assertOk();

        Queue::assertPushed(
            ReconcileWorkspaceEntitlements::class,
            fn (ReconcileWorkspaceEntitlements $job) => $job->clientId === $client->id,
        );
        $this->assertNull($this->cacheRow($workspace->id),
            'The reassignment left its old entitlement row in place and therefore still depends on a queue worker.');

        app(EntitlementResolver::class)->flush();
        $this->assertTrue(app(Entitlements::class)->forWorkspace($workspace->id)->allows(EnsureFlowsEnabled::KEY),
            'The next read did not rebuild from the reassigned plan.');
    }

    #[Test]
    public function source_hash_changes_for_every_mutable_plan_input_the_synthesizer_reads(): void
    {
        ['client' => $client, 'plan' => $plan] = $this->workspaceOnPlan(false, []);
        $cache = app(EntitlementCache::class);

        $original = $cache->sourceHash($client->fresh());

        $plan->update(['whatsapp_flows_enabled' => true]);
        $afterFlows = $cache->sourceHash($client->fresh());
        $this->assertNotSame($original, $afterFlows, 'Flows is a legacy plan flag but did not participate in source_hash.');

        $plan->update(['white_label_enabled' => true]);
        $afterWhiteLabel = $cache->sourceHash($client->fresh());
        $this->assertNotSame($afterFlows, $afterWhiteLabel, 'White-label is a legacy plan flag but did not participate in source_hash.');

        $plan->update(['limits' => ['smart_qr_max_assigned' => 5]]);
        $afterLimits = $cache->sourceHash($client->fresh());
        $this->assertNotSame($afterWhiteLabel, $afterLimits,
            'Limits drive both quota values and Smart QR’s legacy flag but did not participate in source_hash.');
    }
}
