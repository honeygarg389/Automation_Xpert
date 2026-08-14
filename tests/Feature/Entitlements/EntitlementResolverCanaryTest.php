<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Services\PlanPackageSynthesizer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE CANARY. Slice 2 exists to pass this file and nothing else.
 *
 * The obligation, stated exactly: for EVERY seeded plan and EVERY limit key, the
 * resolver's answer must equal what `EnforceLimit` reads today —
 * `$plan->limits[$key] ?? null`. If that holds, the mechanism is proven before
 * anything depends on it. If it does not, nothing has been migrated yet and we
 * find out now rather than after slice 3 has rewired the middleware.
 *
 * ─── ⚠️ WHAT THIS FILE CANNOT PROVE ─────────────────────────────────────────
 *
 * Every seeded plan synthesizes EXACTLY ONE package, and a customer holds one
 * plan. So the fold here is a fold over a single bundle — and a single bundle
 * folds identically whether packages are dominant or summed, because there is
 * nothing to dominate and nothing to sum with.
 *
 * That means **breaking the dominant rule does not fail this test**. It is
 * measured, not assumed: making packages sum leaves every assertion below green.
 *
 * The dominance, additivity and unlimited rules are discriminated in
 * `EntitlementResolverFoldTest`, which constructs the multi-bundle cases this
 * one structurally cannot. Both files are the canary; neither is sufficient.
 */
class EntitlementResolverCanaryTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): EntitlementResolver
    {
        return new EntitlementResolver;
    }

    /** A workspace whose client is on `$plan` by admin assignment. */
    private function workspaceOnPlan(Plan $plan): Workspace
    {
        $client = Client::factory()->create();

        ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

        return Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
    }

    // ══ THE CANARY ═════════════════════════════════════════════════════════

    /**
     * Every seeded plan, every key, against what the middleware reads today.
     *
     * The comparison is deliberately written as the LITERAL expression
     * `EnforceLimit` uses — `$limits[$key] ?? null` — rather than a tidied-up
     * equivalent. A paraphrase of the thing under test is not the thing under
     * test.
     */
    #[Test]
    public function the_resolver_returns_exactly_what_enforce_limit_reads_today(): void
    {
        $this->seed(PlanSeeder::class);

        $plans = Plan::all();
        $this->assertGreaterThanOrEqual(3, $plans->count(), 'Expected the seeded plans to exist.');

        $keysChecked = 0;
        $nonNullChecked = 0;

        foreach ($plans as $plan) {
            $workspace = $this->workspaceOnPlan($plan);
            $entitlement = $this->resolver()->for($workspace->id);

            $limits = $plan->limits ?? [];
            $this->assertNotEmpty($limits, "Plan {$plan->slug} seeded no limits — the loop below "
                .'would then assert nothing at all.');

            foreach ($limits as $key => $_) {
                $today = $limits[$key] ?? null;              // literally EnforceLimit's expression
                $resolved = $entitlement->limit($key);

                $this->assertSame($today, $resolved,
                    "Plan '{$plan->slug}', key '{$key}': the resolver says "
                    .var_export($resolved, true).' but EnforceLimit reads '
                    .var_export($today, true).' today.');

                $keysChecked++;
                if ($today !== null) {
                    $nonNullChecked++;
                }
            }
        }

        // ⚠️ Anti-vacuity. Without these two the loop is satisfied by a resolver
        // that returns null for everything AND a seeder that limits nothing —
        // both sides agreeing on emptiness rather than on logic.
        // ⚠️ 48 -> 51: 17 keys x 3 plans. `smart_qr_max_assigned` was seeded on
        // all three tiers in slice 3 (R-13), so the product moved by exactly 3.
        $this->assertSame(51, $keysChecked,
            'Expected 17 keys x 3 plans. A different number means the seeder changed and this '
            .'test is no longer covering what it claims to.');

        $this->assertGreaterThanOrEqual(30, $nonNullChecked,
            'Almost every assertion compared null to null. That is two empty answers agreeing, '
            .'not a resolver reproducing real limits.');
    }

    /**
     * The negative control for the above: a DELIBERATELY WRONG expectation must
     * fail.
     *
     * Without this, a resolver returning null for every key would still satisfy
     * `assertSame($today, $resolved)` on the unlimited plan's keys, and it is
     * worth proving the comparison can actually fail at all.
     */
    #[Test]
    public function the_canary_comparison_is_capable_of_failing(): void
    {
        $this->seed(PlanSeeder::class);

        $plan = Plan::whereNotNull('limits')->get()
            ->first(fn (Plan $p) => collect($p->limits)->contains(fn ($v) => $v !== null));

        $this->assertNotNull($plan, 'Expected at least one plan with a finite limit.');

        $workspace = $this->workspaceOnPlan($plan);
        $entitlement = $this->resolver()->for($workspace->id);

        $key = collect($plan->limits)->filter(fn ($v) => $v !== null)->keys()->first();
        $real = $plan->limits[$key];

        $this->assertSame($real, $entitlement->limit($key));
        $this->assertNotSame($real + 1, $entitlement->limit($key),
            'The resolver agreed with a value that is off by one, so the comparison above '
            .'proves nothing.');
    }

    // ══ Faithful key migration — BUG-025 must not be "fixed" in passing ════

    /**
     * `storage` stays `storage`, in megabytes.
     *
     * Renaming it to `storage_gb` here would change every customer's quota in
     * both directions and unannounced. That is BUG-025's own decision with its
     * own data migration, not something a synthesis step gets to make.
     */
    #[Test]
    public function the_storage_key_is_migrated_faithfully_and_not_renamed(): void
    {
        $this->seed(PlanSeeder::class);

        $plan = Plan::where('slug', 'starter')->first() ?? Plan::first();
        $workspace = $this->workspaceOnPlan($plan);
        $entitlement = $this->resolver()->for($workspace->id);

        $this->assertTrue($entitlement->has('storage'),
            'The storage key was dropped or renamed during synthesis.');
        $this->assertSame($plan->limits['storage'], $entitlement->limit('storage'));
        $this->assertFalse($entitlement->has('storage_gb'),
            'Synthesis invented a storage_gb key. That is BUG-025 being "fixed" silently inside '
            .'a migration, which changes every customer quota in both directions.');
    }

    /** Every legacy key carries a declared kind and unit — none inferred. */
    #[Test]
    public function every_seeded_limit_key_has_a_declared_kind_and_unit(): void
    {
        $this->seed(PlanSeeder::class);

        $seeded = collect(Plan::all())->flatMap(fn (Plan $p) => array_keys($p->limits ?? []))->unique();

        $this->assertCount(17, $seeded, 'Expected 17 distinct seeded limit keys.');
        $this->assertContains('smart_qr_max_assigned', $seeded,
            'The Smart QR limit is not seeded. R-13 requires a finite value on every tier so '
            .'R-8\'s refusal is reachable in a real installation — a gate that cannot fire is '
            .'not a gate.');

        foreach ($seeded as $key) {
            $this->assertArrayHasKey($key, PlanPackageSynthesizer::LEGACY_KEYS,
                "Limit key '{$key}' has no declared kind/unit. Inferring it from the key's "
                .'spelling is precisely the mistake BUG-025 is made of.');
        }

        $kinds = collect(PlanPackageSynthesizer::LEGACY_KEYS)->countBy('kind');
        $this->assertSame(7, $kinds['counter'] ?? 0, 'Expected 7 counters.');
        $this->assertSame(10, $kinds['gauge'] ?? 0,
            'Expected 10 gauges — BUG-024 originally recorded 7 and missed `automations`; \n'
            .'`smart_qr_max_assigned` is the tenth, added in Smart QR slice 3.');
    }

    // ══ Legacy values: refuse rather than guess ════════════════════════════

    /**
     * ⚠️ A non-numeric legacy limit is REFUSED, not coerced and not skipped.
     *
     * Both of the alternatives resolve the key to null, and null is read as
     * unlimited by every consumer — which is BUG-023's exact failure class. A
     * corrupt plan row must stop the resolver, not silently entitle a customer
     * to everything.
     */
    #[Test]
    public function a_non_numeric_legacy_limit_is_refused_rather_than_coerced(): void
    {
        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 'unlimited']]);
        $workspace = $this->workspaceOnPlan($plan);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/Refusing to guess/');

        $this->resolver()->for($workspace->id);
    }

    /** A numeric string IS accepted — it is an unambiguous JSON round-trip. */
    #[Test]
    public function a_numeric_string_legacy_limit_is_accepted_and_cast(): void
    {
        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => '25']]);
        $workspace = $this->workspaceOnPlan($plan);

        $this->assertSame(25, $this->resolver()->for($workspace->id)->limit('campaigns_per_month'),
            'A numeric string is unambiguous and must be cast, not refused — refusing it would '
            .'make the tripwire fire on ordinary data.');
    }

    // ══ Absent client / absent plan ════════════════════════════════════════

    /**
     * A workspace with no plan resolves to an EMPTY entitlement — which today
     * means unlimited, matching `EnforceLimit`.
     *
     * ⚠️ This is BUG-023 preserved deliberately, not endorsed. Slice 2's job is
     * to reproduce today's behaviour exactly; changing it here would hide
     * whether the fold works behind a behaviour change nobody asked for.
     */
    #[Test]
    public function a_workspace_with_no_plan_resolves_to_an_empty_entitlement(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);

        $entitlement = $this->resolver()->for($workspace->id);

        $this->assertSame([], $entitlement->limits());
        $this->assertNull($entitlement->limit('campaigns_per_month'));
        $this->assertFalse($entitlement->has('campaigns_per_month'),
            'has() must distinguish "never granted" from "granted, unlimited" even while '
            .'limit() returns null for both.');
    }

    #[Test]
    public function an_unknown_workspace_resolves_to_an_empty_entitlement(): void
    {
        $this->assertSame([], $this->resolver()->for(999999)->limits());
    }

    // ══ The memo ═══════════════════════════════════════════════════════════

    /** Request-scoped, and it must not survive a flush. */
    #[Test]
    public function the_memo_returns_the_same_instance_and_clears_on_flush(): void
    {
        $this->seed(PlanSeeder::class);
        $workspace = $this->workspaceOnPlan(Plan::first());

        $resolver = $this->resolver();
        $first = $resolver->for($workspace->id);

        $this->assertSame($first, $resolver->for($workspace->id), 'Second call re-folded.');

        $resolver->flush();

        $this->assertNotSame($first, $resolver->for($workspace->id),
            'The memo survived flush(), so a long-running worker would serve one tenant’s '
            .'entitlement to the next.');
    }

    /** ⚠️ The memo must not answer one workspace with another's entitlement. */
    #[Test]
    public function the_memo_is_keyed_per_workspace(): void
    {
        $this->seed(PlanSeeder::class);

        $plans = Plan::all();
        $a = $this->workspaceOnPlan($plans[0]);
        $b = $this->workspaceOnPlan($plans[1]);

        $resolver = $this->resolver();

        $this->assertSame(
            $plans[0]->limits['campaigns_per_month'] ?? null,
            $resolver->for($a->id)->limit('campaigns_per_month')
        );
        $this->assertSame(
            $plans[1]->limits['campaigns_per_month'] ?? null,
            $resolver->for($b->id)->limit('campaigns_per_month'),
            'The second workspace got the first one’s answer — the memo is not keyed by '
            .'workspace, which is a cross-tenant entitlement leak.'
        );
    }
}
