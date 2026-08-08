<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ EVERY TEST IN THE ORIGINAL VERSION OF THIS FILE WAS VACUOUS.
 *
 * All three POSTed to `app/broadcasts/campaigns` — the campaign STORE route,
 * which does not carry the `limit` middleware. Only
 * `app/broadcasts/campaigns/{campaign}/launch` does. `EnforceLimit` never ran in
 * any of them.
 *
 * They passed anyway because their assertions could not fail:
 *
 *   it_allows_request_when_under_limit  assertNotEquals(402) — true of a 500,
 *                                       a 403, a 419, a validation redirect.
 *   it_blocks_request_when_at_limit     assertContains([402, 302]) — and the
 *                                       route returns 302 either way.
 *   null_limit_means_unlimited          same.
 *
 * They also seeded the meter row DIRECTLY with `UsageMeter::create`, so they
 * never exercised `track()` — which is how the meter went its whole life
 * without accumulating.
 *
 * Rewritten against `app/leads/scrape`, which really does carry
 * `limit:lead_credits_per_month,lead_credits` (verified by enumerating every
 * route's gathered middleware, not by reading the route file) and whose
 * controller has no external preconditions. The discriminator is a DATABASE
 * SIDE EFFECT — a `lead_scrape_jobs` row that exists only if the request reached
 * the controller — rather than a status code the route would return anyway.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/app/leads/scrape';

    private const PAYLOAD = ['keyword' => 'plumbers', 'location' => 'Leeds'];

    private function scrapeJobCount(int $workspaceId): int
    {
        return (int) DB::table('lead_scrape_jobs')->where('workspace_id', $workspaceId)->count();
    }

    // ══ The middleware actually runs ═══════════════════════════════════════

    /**
     * POSITIVE CONTROL, and the one that would have caught the original bug:
     * the route under test must genuinely carry the middleware.
     *
     * Without this, every assertion below could go green against a route where
     * `EnforceLimit` is absent — which is precisely what happened.
     */
    #[Test]
    public function the_route_under_test_carries_the_limit_middleware(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->uri() === ltrim(self::ROUTE, '/') && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'The route under test does not exist.');
        $this->assertContains('limit:lead_credits_per_month,lead_credits', $route->gatherMiddleware(),
            'The route under test does not carry EnforceLimit, so every other assertion in '
            .'this file would be vacuous.');
    }

    // ══ Enforcement, on the admin-assigned path ════════════════════════════

    #[Test]
    public function a_request_under_the_limit_reaches_the_controller(): void
    {
        $user = $this->clientUserWithAssignedPlan(['lead_credits_per_month' => 5]);

        UsageMeter::track($user->workspace_id, 'lead_credits', 4);

        $response = $this->actingAs($user)->post(self::ROUTE, self::PAYLOAD);

        $response->assertSessionHas('success');
        $this->assertSame(1, $this->scrapeJobCount($user->workspace_id),
            'Under the limit, the request must reach the controller and create the job.');
    }

    #[Test]
    public function a_request_at_the_limit_is_refused_and_never_reaches_the_controller(): void
    {
        $user = $this->clientUserWithAssignedPlan(['lead_credits_per_month' => 5]);

        UsageMeter::track($user->workspace_id, 'lead_credits', 5);

        $this->actingAs($user)
            ->postJson(self::ROUTE, self::PAYLOAD)
            ->assertStatus(402)
            ->assertJson(['upgrade_required' => true, 'limit' => 5, 'current' => 5]);

        $this->assertSame(0, $this->scrapeJobCount($user->workspace_id),
            'The middleware returned 402 but the controller still ran.');
    }

    /**
     * ⚠️ The test that proves the METER fix, end to end through the middleware.
     *
     * Five separate `track()` calls, not one seeded row. Under the old
     * implementation the counter would read 1 here and the request would sail
     * through — which is exactly what production did.
     */
    #[Test]
    public function usage_accumulated_one_call_at_a_time_still_reaches_the_limit(): void
    {
        $user = $this->clientUserWithAssignedPlan(['lead_credits_per_month' => 5]);

        for ($i = 0; $i < 5; $i++) {
            UsageMeter::track($user->workspace_id, 'lead_credits');
        }

        $this->actingAs($user)->postJson(self::ROUTE, self::PAYLOAD)->assertStatus(402);
        $this->assertSame(0, $this->scrapeJobCount($user->workspace_id));
    }

    #[Test]
    public function a_null_limit_means_unlimited(): void
    {
        $user = $this->clientUserWithAssignedPlan(['lead_credits_per_month' => null]);

        UsageMeter::track($user->workspace_id, 'lead_credits', 99999);

        $this->actingAs($user)->post(self::ROUTE, self::PAYLOAD)->assertSessionHas('success');
        $this->assertSame(1, $this->scrapeJobCount($user->workspace_id));
    }

    // ══ BUG-023 — the self-serve cohort, and report-only ═══════════════════

    /**
     * A self-serve customer bills through `subscriptions`, so `activePlan()`
     * returns null and the limit reads as unlimited.
     *
     * This is the current, shipped behaviour and the flag defaults to keeping
     * it. The test asserts the STATUS QUO deliberately — flipping the flag is a
     * business decision, and this pins that nothing changed underneath it.
     */
    #[Test]
    public function a_self_serve_customer_is_not_blocked_while_the_flag_is_off(): void
    {
        config(['entitlements.enforce_effective_plan_source' => false]);

        $user = $this->clientUserWithSelfServePlan(['lead_credits_per_month' => 5]);
        UsageMeter::track($user->workspace_id, 'lead_credits', 50);

        $this->actingAs($user)->post(self::ROUTE, self::PAYLOAD)->assertSessionHas('success');
        $this->assertSame(1, $this->scrapeJobCount($user->workspace_id),
            'Report-only mode must not block. If this fails, the correction shipped hot.');
    }

    /** …and while not blocking, it must SAY it would have. */
    #[Test]
    public function report_only_logs_the_request_it_would_have_refused(): void
    {
        config(['entitlements.enforce_effective_plan_source' => false]);

        // Listening to MessageLogged rather than mocking the Log facade: the
        // facade mock made this assertion fail for a reason that had nothing to
        // do with the middleware, which is the wrong thing for a test to be
        // sensitive to.
        //
        // This listener is also what caught the real defect here. The first
        // version logged via the default channel, and captured NOTHING — because
        // `.env.example` ships LOG_LEVEL=error, so every warning() in this
        // application is discarded. Report-only would have run in production
        // writing an empty file, which reads as "no customers affected".
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = [$e->message, $e->context];
        });

        $user = $this->clientUserWithSelfServePlan(['lead_credits_per_month' => 5]);
        UsageMeter::track($user->workspace_id, 'lead_credits', 50);

        $this->actingAs($user)->post(self::ROUTE, self::PAYLOAD);

        $entries = array_values(array_filter(
            $logged,
            fn (array $e) => str_contains($e[0], 'entitlements.report_only')
        ));

        $this->assertCount(1, $entries, 'Report-only blocked nothing AND said nothing, which is '
            .'the same as not having shipped it.');
        $this->assertSame(5, $entries[0][1]['shadow_limit']);
        $this->assertSame(50, $entries[0][1]['usage']);
        $this->assertNull($entries[0][1]['current_limit']);
    }

    /** POSITIVE CONTROL: flipping the flag makes the same request refuse. */
    #[Test]
    public function flipping_the_flag_enforces_the_self_serve_customers_limit(): void
    {
        config(['entitlements.enforce_effective_plan_source' => true]);

        $user = $this->clientUserWithSelfServePlan(['lead_credits_per_month' => 5]);
        UsageMeter::track($user->workspace_id, 'lead_credits', 50);

        $this->actingAs($user)->postJson(self::ROUTE, self::PAYLOAD)->assertStatus(402);
        $this->assertSame(0, $this->scrapeJobCount($user->workspace_id),
            'With the flag on, the self-serve cohort must actually be refused — otherwise '
            .'report-only reports a flip that would do nothing.');
    }

    /**
     * The flag must not disturb the admin-assigned path: `effectivePlan()`
     * prefers the client subscription, so the answer is the same either way.
     */
    #[Test]
    public function the_flag_does_not_change_the_admin_assigned_path(): void
    {
        config(['entitlements.enforce_effective_plan_source' => true]);

        $user = $this->clientUserWithAssignedPlan(['lead_credits_per_month' => 5]);
        UsageMeter::track($user->workspace_id, 'lead_credits', 4);

        $this->actingAs($user)->post(self::ROUTE, self::PAYLOAD)->assertSessionHas('success');
        $this->assertSame(1, $this->scrapeJobCount($user->workspace_id));
    }

    // ══ Fixtures ═══════════════════════════════════════════════════════════

    /** @param  array<string, int|null>  $limits */
    private function clientUserWithAssignedPlan(array $limits): User
    {
        [$plan, $client, $user] = $this->planClientUser($limits);

        ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * The cohort BUG-023 is about: billed through a gateway, so the plan hangs
     * off `subscriptions` and there is no `client_subscriptions` row at all.
     *
     * @param  array<string, int|null>  $limits
     */
    private function clientUserWithSelfServePlan(array $limits): User
    {
        [$plan, , $user] = $this->planClientUser($limits);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'gateway' => 'stripe',
        ]);

        return $user;
    }

    /**
     * @param  array<string, int|null>  $limits
     * @return array{0: Plan, 1: Client, 2: User}
     */
    private function planClientUser(array $limits): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);

        $client = Client::create([
            'name' => 'Test Client',
            'email' => fake()->unique()->safeEmail(),
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'client',
            'client_id' => $client->id,
            'email_verified_at' => now(),
        ]);

        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'client_id' => $client->id,
        ]);

        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$plan, $client, $user];
    }
}
