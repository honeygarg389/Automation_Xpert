<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Ecommerce\Services\ContactCapacity;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\Entitlements;
use App\Services\MediaService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE BRAKE, PULLED ON THE REAL ENFORCING SITES.
 *
 * Phase 0 shipped `ENFORCE_WORKSPACE_SCOPE` proven only against `ScopedFixture`,
 * a model declared inside the test suite. That proved the scope class read the
 * flag; it did not prove that pulling the brake restored access to `Contact`.
 * The correction — `EmergencyBrakeOnRealModelTest` — found something the fixture
 * version could not have: with the brake off, route-model binding resolves
 * foreign rows again and the controllers' own checks hold, so the two layers are
 * independent.
 *
 * So this file never uses a fixture. Every test drives a REAL enforcing site
 * with a REAL seeded plan, brake on and brake off, and asserts the decision is
 * identical.
 *
 * ─── The three sites, and what a WRONG facade answer would produce ──────────
 *
 *   EnforceLimit      `lead_credits_per_month` is a real seeded key with real
 *                     values, so a wrong answer flips 402 to success or back.
 *                     Fully discriminating.
 *
 *   MediaService      asks `storage_gb`, which NO plan carries — it appears in
 *                     no seeder and not in the admin form's key list. Both paths
 *                     therefore return null and fall through to `?? 1`, and the
 *                     quota is 1 GB either way. **A comparison on a seeded plan
 *                     cannot discriminate here**, so those tests set the key
 *                     explicitly to prove the wiring is live rather than
 *                     accidentally equal.
 *
 *   ContactCapacity   same shape: `max_contacts` exists nowhere. Same treatment.
 */
class EntitlementFacadeBrakeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{client: Client, user: User, workspace: Workspace} */
    private function customerOn(Plan $plan): array
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

        $user = User::factory()->create([
            'role' => 'client', 'client_id' => $client->id, 'email_verified_at' => now(),
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['client' => $client, 'user' => $user->refresh(), 'workspace' => $workspace];
    }

    private function withBrake(bool $enabled, callable $fn): mixed
    {
        config(['entitlements.enabled' => $enabled]);
        app()->forgetInstance(EntitlementResolver::class);
        app()->forgetInstance(Entitlements::class);

        return $fn();
    }

    // ══ SITE 1 — EnforceLimit, fully discriminating ════════════════════════

    /**
     * The site where a wrong facade answer is unmistakable: a real seeded key
     * with a real value, driven through the real route, asserting a real 402.
     */
    #[Test]
    public function enforce_limit_refuses_identically_with_the_brake_on_and_off(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $limit = $plan->limits['lead_credits_per_month'];

        $this->assertIsInt($limit, 'The seeded starter plan must carry a finite lead credit '
            .'limit, or this test compares two unlimiteds.');

        foreach ([true, false] as $brake) {
            $c = $this->customerOn($plan);
            UsageMeter::track($c['workspace']->id, 'lead_credits', $limit);

            $status = $this->withBrake($brake, fn () => $this->actingAs($c['user'])
                ->postJson('/app/leads/scrape', ['keyword' => 'x', 'location' => 'y'])
                ->getStatusCode());

            $this->assertSame(402, $status,
                'With the brake '.($brake ? 'ON' : 'OFF').' the limit was not enforced.');
        }
    }

    /** POSITIVE CONTROL: under the limit, both paths allow. */
    #[Test]
    public function enforce_limit_allows_identically_with_the_brake_on_and_off(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', 'starter')->firstOrFail();

        foreach ([true, false] as $brake) {
            $c = $this->customerOn($plan);
            UsageMeter::track($c['workspace']->id, 'lead_credits', 1);

            $this->withBrake($brake, fn () => $this->actingAs($c['user'])
                ->post('/app/leads/scrape', ['keyword' => 'x', 'location' => 'y'])
                ->assertSessionHas('success'));
        }
    }

    /**
     * Every seeded plan, every key, both brake positions — the decision the
     * middleware would make must be byte-identical.
     */
    #[Test]
    public function every_seeded_plan_and_key_decides_identically_either_side_of_the_brake(): void
    {
        $this->seed(PlanSeeder::class);

        $compared = 0;
        $finite = 0;

        foreach (Plan::all() as $plan) {
            $c = $this->customerOn($plan);

            foreach (array_keys($plan->limits ?? []) as $key) {
                $on = $this->withBrake(true, fn () => app(Entitlements::class)
                    ->limitForClient($c['client'], $key));
                $off = $plan->limits[$key] ?? null;   // literally the legacy expression

                $this->assertSame($off, $on, "Plan '{$plan->slug}', key '{$key}' decided "
                    .'differently either side of the brake.');

                $compared++;
                if ($off !== null) {
                    $finite++;
                }
            }
        }

        $this->assertSame(48, $compared, 'Expected 16 keys x 3 plans.');
        $this->assertGreaterThanOrEqual(30, $finite,
            'Almost every comparison was null against null — two empty answers agreeing, not '
            .'two paths agreeing.');
    }

    // ══ SITE 2 — MediaService ══════════════════════════════════════════════

    /**
     * ⚠️ NOT DISCRIMINATING ON ITS OWN, and it says so.
     *
     * `storage_gb` is carried by no plan, so both paths return null and both
     * fall through to `?? 1`. This asserts BUG-025 is preserved — the quota is
     * still wrong, still 1 GB, and the facade did not "fix" it in passing, which
     * would have been a silent entitlement change in both directions.
     */
    #[Test]
    public function media_quota_stays_one_gigabyte_either_side_of_the_brake(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', 'enterprise')->first() ?? Plan::orderByDesc('id')->first();
        $c = $this->customerOn($plan);

        $this->assertArrayNotHasKey('storage_gb', $plan->limits ?? [],
            'Precondition: no plan carries storage_gb. If one does, this test is now '
            .'discriminating and the comment above is stale.');

        $oneGb = 1024 ** 3;

        foreach ([true, false] as $brake) {
            $bytes = $this->withBrake($brake, fn () => app(MediaService::class)->quotaBytes($c['user']));

            $this->assertSame($oneGb, $bytes,
                'The storage quota changed with the brake '.($brake ? 'ON' : 'OFF').'. BUG-025 '
                .'must be preserved here, not fixed in passing — a rename changes every '
                .'customer quota in both directions.');
        }
    }

    /**
     * THE DISCRIMINATING VERSION. Sets `storage_gb` explicitly, so a facade that
     * silently returned null would now be caught.
     *
     * Without this, the test above passes against a facade wired to nothing.
     */
    #[Test]
    public function media_quota_reads_a_real_storage_gb_value_through_the_facade(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage_gb' => 25]]);
        $c = $this->customerOn($plan);

        $expected = 25 * (1024 ** 3);

        foreach ([true, false] as $brake) {
            $bytes = $this->withBrake($brake, fn () => app(MediaService::class)->quotaBytes($c['user']));

            $this->assertSame($expected, $bytes,
                'With the brake '.($brake ? 'ON' : 'OFF').' the facade did not read a present '
                .'storage_gb — so the previous test was comparing two fallbacks, not two paths.');
        }
    }

    /**
     * ⚠️ THE DIVERGENCE THAT THE FLAG FLIP CLOSED — and how it was found.
     *
     * `MediaService` reads `User::effectiveSubscription()`. The resolver reads
     * whichever source `entitlements.enforce_effective_plan_source` selects. When
     * slice 3 was written that flag defaulted to FALSE, so the resolver used
     * `Client::activePlan()` and the two disagreed for every self-serve
     * customer. This test asserted the divergence: legacy 25 GB, facade 1 GB.
     *
     * Then BUG-023's flip landed on master and the default became TRUE. Both
     * branches were green in isolation, `git merge-tree` reported zero
     * conflicts, and the merged result FAILED — because the flip changed the
     * resolver's plan source underneath a test that had pinned the old one.
     *
     * That is a clean-but-incoherent merge caught by the suite gate and by
     * nothing else. The dry-run cannot see it: the two branches never touched
     * the same lines.
     *
     * The finding is good news. Flipping the flag ALIGNED the two sources, which
     * means the fourth instance of the one-concept-two-places trap is closed
     * rather than merely documented. This test now pins the alignment, so if
     * anyone reverts the flag or changes either source, the divergence comes
     * back loudly instead of silently.
     */
    #[Test]
    public function the_facade_and_the_legacy_source_now_agree_for_a_self_serve_customer(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage_gb' => 25]]);

        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        // Self-serve: a gateway subscription, NO client_subscriptions row.
        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(), 'gateway' => 'stripe',
        ]);

        $expected = 25 * (1024 ** 3);

        $this->assertTrue(config('entitlements.enforce_effective_plan_source'),
            'Precondition: the flip is what aligns these two sources. With it off they '
            .'diverge, and the assertions below are the wrong ones.');

        $legacy = $this->withBrake(false, fn () => app(MediaService::class)->quotaBytes($user));
        $facade = $this->withBrake(true, fn () => app(MediaService::class)->quotaBytes($user));

        $this->assertSame($expected, $legacy,
            'Legacy reads effectiveSubscription(), which finds a self-serve plan.');
        $this->assertSame($expected, $facade,
            'The facade must now agree. If this returns 1 GB, the resolver is back on '
            .'activePlan() — which does not see a gateway subscription — and MediaService '
            .'has silently diverged from EnforceLimit again. That divergence is the shape of '
            .'BUG-023 and it is worth failing loudly for.');
    }

    /**
     * …and the divergence is still REAL when the flag is off, which is why the
     * alignment above is a property of the flip rather than a coincidence.
     */
    #[Test]
    public function reverting_the_flag_restores_the_divergence(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage_gb' => 25]]);

        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'billing_cycle' => 'month', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(), 'gateway' => 'stripe',
        ]);

        config(['entitlements.enforce_effective_plan_source' => false]);

        $facade = $this->withBrake(true, fn () => app(MediaService::class)->quotaBytes($user));

        $this->assertSame(1024 ** 3, $facade,
            'With the flag off the resolver uses activePlan(), which cannot see a gateway '
            .'subscription, so the facade falls through to 1 GB while the legacy path returns '
            .'25 GB. Pinned so the alignment above is understood as a consequence of the flip, '
            .'not as two paths that were always the same.');
    }

    // ══ SITE 3 — ContactCapacity ═══════════════════════════════════════════

    /** `max_contacts` exists nowhere, so both paths must return null. */
    #[Test]
    public function contact_capacity_is_unlimited_either_side_of_the_brake(): void
    {
        $this->seed(PlanSeeder::class);
        $c = $this->customerOn(Plan::first());

        foreach ([true, false] as $brake) {
            $this->assertNull(
                $this->withBrake($brake, fn () => app(ContactCapacity::class)->remaining($c['workspace']->id)),
                'ContactCapacity started bounding contacts with the brake '.($brake ? 'ON' : 'OFF')
                .'. max_contacts is set by no plan, so introducing a bound here would start '
                .'limiting a table nobody has been limiting.'
            );
        }
    }

    /** THE DISCRIMINATING VERSION: a real `max_contacts`, read through both paths. */
    #[Test]
    public function contact_capacity_reads_a_real_max_contacts_through_the_facade(): void
    {
        $plan = Plan::factory()->create(['limits' => ['max_contacts' => 40]]);
        $c = $this->customerOn($plan);

        foreach ([true, false] as $brake) {
            $this->assertSame(40,
                $this->withBrake($brake, fn () => app(ContactCapacity::class)->remaining($c['workspace']->id)),
                'With the brake '.($brake ? 'ON' : 'OFF').' a present max_contacts was not read — '
                .'so the previous test compared two nulls, not two paths.'
            );
        }
    }

    /** …and it must subtract existing contacts identically on both paths. */
    #[Test]
    public function contact_capacity_subtracts_existing_contacts_identically(): void
    {
        $plan = Plan::factory()->create(['limits' => ['max_contacts' => 40]]);
        $c = $this->customerOn($plan);

        foreach (range(1, 3) as $i) {
            DB::table('contacts')->insert([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $c['workspace']->id,
                'phone_e164' => '+1555000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([true, false] as $brake) {
            $this->assertSame(37,
                $this->withBrake($brake, fn () => app(ContactCapacity::class)->remaining($c['workspace']->id)));
        }
    }

    // ══ ⚠️ IS THE FACADE ACTUALLY CONSULTED? ═══════════════════════════════
    //
    // Every test above compares brake-ON against brake-OFF and asserts they
    // agree. That is the right question for "did behaviour change" — and it is
    // WORTHLESS as proof that the facade is wired, because if the facade were
    // removed entirely both sides would be the legacy path and would agree
    // trivially.
    //
    // Measured: reverting the EnforceLimit swap left this whole file green.
    //
    // So each site also gets a test that binds a facade returning a value the
    // legacy path CANNOT produce, and asserts the site follows it with the brake
    // on and ignores it with the brake off. That is the only shape that can tell
    // "wired" from "coincidentally equal".

    private function bindFacadeReturning(array $answers): void
    {
        $this->app->bind(Entitlements::class, fn () => new class($answers) extends Entitlements
        {
            /** @param array<string, int|null> $answers */
            public function __construct(private array $answers)
            {
                parent::__construct(new EntitlementResolver);
            }

            public function limitForClient(?Client $client, string $key): ?int
            {
                return $this->answers[$key] ?? null;
            }

            public function limitForWorkspace(int $workspaceId, string $key): ?int
            {
                return $this->answers[$key] ?? null;
            }

            public function forClient(?Client $client): Entitlement
            {
                return new Entitlement($this->answers);
            }

            public function forWorkspace(int $workspaceId): Entitlement
            {
                return new Entitlement($this->answers);
            }
        });
    }

    /** SITE 1: the middleware must obey the facade, not the plan row. */
    #[Test]
    public function enforce_limit_obeys_the_facade_and_not_the_plan_row(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $c = $this->customerOn($plan);

        // The plan allows 200; the facade says 0. Usage is 0, so the legacy path
        // would allow and only the facade can refuse.
        $this->bindFacadeReturning(['lead_credits_per_month' => 0]);

        config(['entitlements.enabled' => true]);
        $this->actingAs($c['user'])
            ->postJson('/app/leads/scrape', ['keyword' => 'x', 'location' => 'y'])
            ->assertStatus(402);

        config(['entitlements.enabled' => false]);
        $this->actingAs($c['user'])
            ->post('/app/leads/scrape', ['keyword' => 'x', 'location' => 'y'])
            ->assertSessionHas('success');
    }

    /** SITE 2: the quota must follow the facade. */
    #[Test]
    public function media_quota_obeys_the_facade_and_not_the_plan_row(): void
    {
        $this->seed(PlanSeeder::class);
        $c = $this->customerOn(Plan::first());

        $this->bindFacadeReturning(['storage_gb' => 7]);

        config(['entitlements.enabled' => true]);
        $this->assertSame(7 * (1024 ** 3), app(MediaService::class)->quotaBytes($c['user']),
            'The facade said 7 GB and the site did not follow it — the swap is not wired.');

        config(['entitlements.enabled' => false]);
        $this->assertSame(1024 ** 3, app(MediaService::class)->quotaBytes($c['user']),
            'With the brake OFF the facade must be ignored entirely.');
    }

    /** SITE 3: capacity must follow the facade. */
    #[Test]
    public function contact_capacity_obeys_the_facade_and_not_the_plan_row(): void
    {
        $this->seed(PlanSeeder::class);
        $c = $this->customerOn(Plan::first());

        $this->bindFacadeReturning(['max_contacts' => 5]);

        config(['entitlements.enabled' => true]);
        $this->assertSame(5, app(ContactCapacity::class)->remaining($c['workspace']->id),
            'The facade said 5 and the site did not follow it — the swap is not wired.');

        config(['entitlements.enabled' => false]);
        $this->assertNull(app(ContactCapacity::class)->remaining($c['workspace']->id),
            'With the brake OFF the facade must be ignored entirely.');
    }

    // ══ Plan::hasFeature() is gone ═════════════════════════════════════════

    /**
     * Zero callers, and it returned false for every input except `white_label` —
     * an API that looks like it works and denies everything. Feature checks go
     * through the facade.
     */
    #[Test]
    public function plan_has_feature_no_longer_exists(): void
    {
        $this->assertFalse(method_exists(Plan::class, 'hasFeature'),
            'Plan::hasFeature() is back. It returns false for everything except white_label, '
            .'so anything written against it silently denies.');
    }
}
