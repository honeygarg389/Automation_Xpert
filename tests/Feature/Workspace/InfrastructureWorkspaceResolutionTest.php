<?php

namespace Tests\Feature\Workspace;

use App\Models\Plan;
use App\Support\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 1b — the five infrastructure call sites now resolve the workspace
 * through WorkspaceContext instead of the non-existent `current_workspace_id`.
 *
 * Controllers are NOT part of 1b; they migrate in 1c. The characterisation
 * tests in WorkspaceContextTest therefore still pass after this commit, because
 * they describe controller behaviour.
 */
class InfrastructureWorkspaceResolutionTest extends TestCase
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

    /** Resolve the ai-runs limiter the way the framework does. */
    private function aiRunsLimit(): Limit
    {
        $callback = RateLimiter::limiter('ai-runs');

        return $callback(Request::create('/api/v1/ai/run', 'POST'));
    }

    // ── G-2: the ai-runs limiter ─────────────────────────────────────────────

    /**
     * The bug: $workspaceId was always the client IP, Workspace::find(<ip>)
     * returned null, so every customer got the default 10/min no matter what
     * they had paid for.
     */
    #[Test]
    public function a_paid_plan_receives_its_purchased_ai_run_limit(): void
    {
        ['user' => $user, 'client' => $client] = $this->createTwoWorkspaceUser();

        $plan = Plan::factory()->create(['limits' => ['ai_runs_per_minute' => 100]]);
        $this->attachPlanToClient($client, $plan);

        $this->actingAs($user);

        $this->assertSame(
            100,
            $this->aiRunsLimit()->maxAttempts,
            'A plan granting 100 ai_runs_per_minute must yield 100, not the default 10.'
        );
    }

    /** POSITIVE CONTROL: a workspace with no plan still gets the default. */
    #[Test]
    public function a_workspace_without_a_plan_falls_back_to_the_default_limit(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        $this->actingAs($user);

        $this->assertSame(10, $this->aiRunsLimit()->maxAttempts);
    }

    #[Test]
    public function the_limiter_keys_on_the_workspace_not_the_ip(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $this->actingAs($user);

        $this->assertSame(
            'ws:'.$home->id,
            $this->aiRunsLimit()->key,
            'Keying on the IP made users behind one NAT share a bucket.'
        );
    }

    #[Test]
    public function an_unauthenticated_request_still_falls_back_to_an_ip_key(): void
    {
        $limit = $this->aiRunsLimit();

        $this->assertStringStartsWith('ip:', $limit->key);
        $this->assertSame(10, $limit->maxAttempts);
    }

    /**
     * Regression guard for the latent crash uncovered while fixing G-2:
     * `Workspace::with('client.activePlan')` was an invalid eager load
     * (activePlan() is a method, not a relation). It never threw only because
     * find() returned null. Resolving a real workspace would have raised
     * RelationNotFoundException on the first authenticated hit.
     */
    #[Test]
    public function resolving_a_real_workspace_does_not_raise_a_relation_error(): void
    {
        ['user' => $user, 'client' => $client] = $this->createTwoWorkspaceUser();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['ai_runs_per_minute' => 55]]));

        $this->actingAs($user);

        $limit = $this->aiRunsLimit();

        $this->assertSame(55, $limit->maxAttempts);
    }

    // ── G-3: plan limits follow the ACTIVE workspace ─────────────────────────

    /**
     * EnforceLimit read `current_workspace_id ?? workspace_id`, so limits were
     * always enforced against the HOME workspace. After 1b it resolves through
     * WorkspaceContext and follows the switch.
     */
    #[Test]
    public function enforce_limit_resolves_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session(['current_workspace_id' => $other->id]);
        WorkspaceContext::flush();

        $this->assertSame(
            (int) $other->id,
            WorkspaceContext::id(),
            'Infrastructure now follows the switch; before 1b this was always the home workspace.'
        );
        $this->assertNotSame((int) $home->id, WorkspaceContext::id());
    }

    // ── Query volume ─────────────────────────────────────────────────────────

    /**
     * accessibleWorkspaces() runs two queries and is consulted by
     * WorkspaceContext. Memoisation must hold within a request, or wiring it
     * into middleware that runs on every request would multiply query volume.
     */
    #[Test]
    public function repeated_resolution_is_memoised_within_a_request(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session(['current_workspace_id' => $other->id]);
        WorkspaceContext::flush();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        WorkspaceContext::id();
        $afterFirst = $queries;

        for ($i = 0; $i < 10; $i++) {
            WorkspaceContext::id();
        }

        $this->assertSame(
            $afterFirst,
            $queries,
            'Resolution must be memoised: 10 further calls issued '.($queries - $afterFirst).' extra queries.'
        );
    }
}
