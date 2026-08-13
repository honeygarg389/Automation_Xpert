<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 1, slice 8 — the read-only surfaces.
 *
 * ─── ⚠️ THREE ARE ENTITLEMENT QUESTIONS, THREE ARE CATALOG QUESTIONS ────────
 *
 * An entitlement question is "what may THIS customer do" and needs a client. A
 * catalog question is "what does this PRODUCT contain" and has no customer at
 * all. Forcing the second through the resolver would be the wrong shape — and
 * on a public pricing page it would either return nothing or answer for whoever
 * happened to be logged in, showing one customer's negotiated limits to every
 * visitor.
 *
 *   ENTITLEMENT -> facade      HandleInertiaRequests, DashboardController,
 *                              SubscriptionApiController
 *   CATALOG     -> plans row   PricingController, LandingController,
 *                              Admin\PlanController
 *
 * The catalog three are asserted here to KEEP reading the plan row, so a later
 * reader tidying up "the last three sites that don't use the facade" finds a
 * test explaining why they must not.
 */
class PresentationSitesTest extends TestCase
{
    use RefreshDatabase;

    /** A customer billed through a GATEWAY — no client_subscriptions row. */
    private function selfServeCustomer(array $limits): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);
        $client = Client::factory()->create();
        $user = User::factory()->create([
            'role' => 'client', 'client_id' => $client->id, 'email_verified_at' => now(),
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(), 'gateway' => 'stripe',
        ]);

        return ['client' => $client->refresh(), 'user' => $user->refresh(), 'workspace' => $workspace];
    }

    /** A customer with an ADMIN-ASSIGNED plan. */
    private function assignedCustomer(array $limits): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);
        $client = Client::factory()->create();

        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'client', 'client_id' => $client->id, 'email_verified_at' => now(),
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['client' => $client->refresh(), 'user' => $user->refresh(), 'workspace' => $workspace];
    }

    /** Bind a facade returning values the legacy path cannot produce. */
    private function bindFacadeReturning(array $limits): void
    {
        $this->app->bind(Entitlements::class, fn () => new class($limits) extends Entitlements
        {
            public function __construct(private array $answers)
            {
                parent::__construct(app(EntitlementResolver::class));
            }

            public function forWorkspace(int $workspaceId): Entitlement
            {
                return new Entitlement($this->answers);
            }

            public function forClient(?Client $client): Entitlement
            {
                return new Entitlement($this->answers);
            }

            public function limitForWorkspace(int $workspaceId, string $key): ?int
            {
                return $this->answers[$key] ?? null;
            }

            public function limitForClient(?Client $client, string $key): ?int
            {
                return $this->answers[$key] ?? null;
            }
        });
    }

    // ══ ⚠️ THE DISPLAY CHANGE — a stated finding, not an absorbed one ══════

    /**
     * ⚠️ BEHAVIOUR CHANGES HERE, and it is BUG-023's presentation half.
     *
     * `HandleInertiaRequests` read `activePlan()` — `client_subscriptions` only.
     * For a self-serve customer that returns null, so `$limits` was `[]` and the
     * usage panel rendered NOTHING: no bars, no limits, no sign that a limit
     * existed at all. Meanwhile enforcement was fixed to use the effective plan,
     * so those customers could be refused against a limit their own dashboard
     * never showed them.
     *
     * Through the facade they now see it. This is the one site in the slice
     * whose displayed value changes, and it changes from "silently nothing" to
     * "the truth".
     */
    #[Test]
    public function a_self_serve_customer_now_sees_a_usage_panel_at_all(): void
    {
        $c = $this->selfServeCustomer(['campaigns_per_month' => 5]);

        $legacyPlan = $c['client']->activePlan();
        $this->assertNull($legacyPlan,
            'Precondition: activePlan() sees nothing for a gateway-billed customer — which is '
            .'why the panel used to be empty.');

        $props = $this->actingAs($c['user'])->get('/app/dashboard')
            ->getOriginalContent()->getData()['page']['props'] ?? [];

        $usage = $props['current_workspace_usage'] ?? [];

        $this->assertArrayHasKey('campaigns_per_month', $usage,
            'The self-serve customer still sees an empty usage panel. Enforcement was fixed in '
            .'BUG-023 and the display was not, so they can be refused against a limit their '
            .'dashboard never shows.');
        $this->assertSame(5, $usage['campaigns_per_month']['limit']);
    }

    /** POSITIVE CONTROL: the admin-assigned path is unchanged. */
    #[Test]
    public function an_admin_assigned_customers_panel_is_unchanged(): void
    {
        $c = $this->assignedCustomer(['campaigns_per_month' => 9]);

        $props = $this->actingAs($c['user'])->get('/app/dashboard')
            ->getOriginalContent()->getData()['page']['props'] ?? [];

        $this->assertSame(9, $props['current_workspace_usage']['campaigns_per_month']['limit'] ?? null,
            'The admin-assigned path changed. Its plan source already agreed with the '
            .'resolver, so it must not.');
    }

    /**
     * Unlimited keys stay OUT of the usage panel — unchanged, and a display
     * choice rather than an entitlement one.
     *
     * A progress bar needs a denominator and "unlimited" has none. The facade
     * reports the key as granted-and-unlimited; this panel chooses not to draw
     * it. That is the divergence slice 0 found, now reduced to presentation.
     */
    #[Test]
    public function unlimited_keys_are_still_omitted_from_the_usage_panel(): void
    {
        $c = $this->assignedCustomer(['campaigns_per_month' => null, 'chatbots' => 4]);

        $props = $this->actingAs($c['user'])->get('/app/dashboard')
            ->getOriginalContent()->getData()['page']['props'] ?? [];
        $usage = $props['current_workspace_usage'] ?? [];

        $this->assertArrayNotHasKey('campaigns_per_month', $usage,
            'An unlimited key appeared in the usage panel with no denominator to draw against.');
        $this->assertArrayHasKey('chatbots', $usage,
            'Positive control: a bounded key on the same plan must still appear, or the '
            .'assertion above passes against an empty panel.');
    }

    // ══ Wiring — the facade must be consulted, not coincidentally agree ═════

    /** SITE 1: the Inertia usage panel obeys the facade. */
    #[Test]
    public function the_usage_panel_obeys_the_facade(): void
    {
        $c = $this->assignedCustomer(['campaigns_per_month' => 9]);
        $this->bindFacadeReturning(['campaigns_per_month' => 4242]);

        $props = $this->actingAs($c['user'])->get('/app/dashboard')
            ->getOriginalContent()->getData()['page']['props'] ?? [];

        $this->assertSame(4242, $props['current_workspace_usage']['campaigns_per_month']['limit'] ?? null,
            'The panel ignored the facade and read the plan row — the swap is not wired.');
    }

    /** SITE 2: the dashboard seat limit obeys the facade. */
    #[Test]
    public function the_dashboard_seat_limit_obeys_the_facade(): void
    {
        $c = $this->assignedCustomer(['users' => 3]);
        $this->bindFacadeReturning(['users' => 77]);

        $props = $this->actingAs($c['user'])->get('/app/dashboard')
            ->getOriginalContent()->getData()['page']['props'] ?? [];

        $this->assertSame(77, data_get($props, 'usage.team_members_limit'),
            'The dashboard seat limit ignored the facade.');
    }

    /** SITE 3: the usage API obeys the facade. */
    #[Test]
    public function the_usage_api_obeys_the_facade(): void
    {
        $c = $this->assignedCustomer(['users' => 3]);
        $this->bindFacadeReturning(['users' => 55]);

        $token = $c['user']->createToken('t', ['*'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('data.limits.users', 55);
    }

    // ══ ⚠️ CATALOG SITES — asserted to KEEP reading the plan row ═══════════

    /**
     * ⚠️ The public pricing page must NOT resolve an entitlement.
     *
     * It has no customer. Routing it through the facade would either return
     * nothing or answer for whoever happens to be authenticated — showing one
     * customer's negotiated limits to every visitor.
     *
     * Asserted by binding a facade that returns an unmistakable value and
     * proving the page does not show it.
     */
    #[Test]
    public function the_public_pricing_page_resolves_no_entitlement(): void
    {
        Plan::factory()->create(['limits' => ['campaigns_per_month' => 11], 'enabled' => true]);

        // A facade that would be unmistakable if it were consulted.
        $this->bindFacadeReturning(['campaigns_per_month' => 4242]);

        $response = $this->get('/pricing');
        $response->assertOk();

        $props = $response->getOriginalContent()->getData()['page']['props'] ?? [];

        $this->assertNotEmpty($props['plans'] ?? [],
            'Positive control: the pricing page must render plans, or the assertion below '
            .'passes against an empty page.');

        $this->assertStringNotContainsString('4242', json_encode($props),
            'The public pricing page rendered a resolved ENTITLEMENT. It has no customer — '
            .'resolving one here shows every visitor whatever the logged-in user happens to '
            .'hold.');
    }

    /**
     * ⚠️ Incidental finding: the pricing page does not render limits at all.
     *
     * `PricingController` builds a `limits` key, but the props actually reaching
     * `/pricing` are id, name, description, price_monthly, price_yearly,
     * features, is_featured, trial_days — no limits. So the page advertises
     * features and prices and never shows a customer what they are buying in
     * numbers.
     *
     * Recorded as a test rather than a bug because it may well be deliberate:
     * a pricing page that lists sixteen numeric limits is a worse pricing page.
     * But it means the `limits` key built there is dead, and dead code that
     * looks live is what this codebase keeps finding.
     */
    #[Test]
    public function the_pricing_page_renders_no_limits_today(): void
    {
        Plan::factory()->create(['limits' => ['campaigns_per_month' => 11], 'enabled' => true]);

        $props = $this->get('/pricing')->getOriginalContent()->getData()['page']['props'] ?? [];

        $this->assertArrayNotHasKey('limits', $props['plans'][0] ?? [],
            'The pricing page has started rendering limits. If that is intended it is a '
            .'CATALOG read and must stay on plans.limits — see this class docblock.');
    }

    /** The admin plan form edits the PLAN, so it must show the plan row. */
    #[Test]
    public function the_admin_plan_form_shows_the_plan_row_not_an_entitlement(): void
    {
        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 11]]);
        $this->bindFacadeReturning(['campaigns_per_month' => 4242]);

        $admin = $this->createSuperAdmin();

        $props = $this->actingAs($admin, 'admin')->get('/admin/plans')
            ->getOriginalContent()->getData()['page']['props'] ?? [];

        $rendered = data_get($props, 'plans.*.limits.campaigns_per_month');

        $this->assertNotContains(4242, $rendered,
            'The admin plan form showed a resolved entitlement. Its Save button writes the '
            .'plan row, so editing a resolved value back into its own source would let a '
            .'partner ceiling silently become the plan.');
        $this->assertContains(11, $rendered, 'Positive control: the plan row must be shown.');
    }
}
