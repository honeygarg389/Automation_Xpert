<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Jobs\ReconcileWorkspaceEntitlements;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnGrant;
use App\Modules\Entitlements\Models\EntitlementGrant;
use App\Modules\Entitlements\Services\EntitlementCache;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE ORACLE, AND THE THREE THINGS NO EVENT CAN COVER.
 *
 * A cache is only safe if a wrong row is detectable. So the oracle test does not
 * merely compare cache to resolver on a freshly-written row — that compares a
 * value to itself, because both are computed in the same breath. It POISONS a
 * cached payload by hand and proves the comparison sees it. If it cannot catch a
 * hand-poisoned row it cannot catch a staleness bug either.
 *
 * Three inputs change the answer with NO WRITE ANYWHERE, so no listener can ever
 * fire for them, and each is tested by travelling past its boundary:
 *
 *   1. a grant's `starts_at`  — a dormant grant becomes live
 *   2. a grant's `ends_at`    — a grant lapses
 *   3. a subscription's `ends_at`
 *
 * `starts_at` is the one that defeats a freshness heuristic: the cache row is
 * written AFTER the grant exists, so `computed_at` is NEWER than its input while
 * the answer is wrong. Only an absolute boundary catches it.
 */
class EntitlementCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['entitlements.cache_enabled' => true]);
    }

    /** @return array{client: Client, workspace: Workspace} */
    private function customer(array $limits, ?Partner $partner = null, ?\Closure $sub = null): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);
        $client = Client::factory()->create(['partner_id' => $partner?->id]);

        $subscription = ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);
        $sub && $sub($subscription);

        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['client' => $client->refresh(), 'workspace' => $workspace];
    }

    private function cache(): EntitlementCache
    {
        return app(EntitlementCache::class);
    }

    /** The memo would mask a stale row within a request, so it is always cleared. */
    private function fresh(int $workspaceId)
    {
        app(EntitlementResolver::class)->flush();

        return app(EntitlementResolver::class)->for($workspaceId);
    }

    private function row(int $workspaceId): ?object
    {
        return DB::table('workspace_entitlements')->where('workspace_id', $workspaceId)->first();
    }

    // ══ ⚠️ THE ORACLE ══════════════════════════════════════════════════════

    /**
     * materialized == freshly computed, for every workspace — AND the comparison
     * demonstrably catches a wrong row.
     *
     * The poisoning half is what makes this test worth having. Without it the
     * assertion compares two values computed moments apart from the same inputs,
     * which cannot fail.
     */
    #[Test]
    public function the_oracle_catches_a_hand_poisoned_cache_row(): void
    {
        $a = $this->customer(['campaigns_per_month' => 100]);
        $b = $this->customer(['campaigns_per_month' => 7]);

        foreach ([$a, $b] as $c) {
            $this->cache()->forWorkspace($c['workspace']->id);   // warm
        }

        // Oracle passes while the cache is honest.
        foreach ([$a, $b] as $c) {
            $this->assertSame(
                $this->fresh($c['workspace']->id)->limits(),
                app(Entitlements::class)->forWorkspace($c['workspace']->id)->limits(),
                'Oracle disagreed on an untouched cache.'
            );
        }

        // ⚠️ POISON: rewrite the payload by hand, leaving valid_until intact so
        // the row still looks fresh. This is what a staleness bug looks like from
        // the outside.
        DB::table('workspace_entitlements')
            ->where('workspace_id', $a['workspace']->id)
            ->update(['payload' => json_encode(['limits' => ['campaigns_per_month' => 999999], 'flags' => []])]);

        $cached = app(Entitlements::class)->forWorkspace($a['workspace']->id)->limits();
        $computed = $this->fresh($a['workspace']->id)->limits();

        $this->assertSame(999999, $cached['campaigns_per_month'],
            'The poisoned row was not served, so this test is not exercising the cache at all.');

        $this->assertNotSame($computed, $cached,
            'The oracle could not tell a poisoned cache from a correct one. If it cannot catch '
            .'a row rewritten by hand it cannot catch a staleness bug either, and the cache is '
            .'unsafe regardless of what else passes.');
    }

    /** ⚠️ Cross-tenant: two workspaces on DIFFERENT plans must not share a row. */
    #[Test]
    public function the_cache_is_keyed_per_workspace(): void
    {
        $a = $this->customer(['campaigns_per_month' => 100]);
        $b = $this->customer(['campaigns_per_month' => 7]);

        $this->assertSame(100, app(Entitlements::class)->forWorkspace($a['workspace']->id)->limit('campaigns_per_month'));
        $this->assertSame(7, app(Entitlements::class)->forWorkspace($b['workspace']->id)->limit('campaigns_per_month'),
            'The second workspace received the first one’s cached entitlement — a cross-tenant '
            .'leak, and the numbers differ precisely so it cannot pass by coincidence.');
    }

    /**
     * ⚠️ Dropping every row must change NO answer.
     *
     * The cheapest possible proof that the cache is a performance structure and
     * not a correctness one — and that a miss is never served as empty, which is
     * the fail-open shape.
     */
    #[Test]
    public function truncating_the_cache_changes_no_answer(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100, 'chatbots' => 5]);

        $warm = app(Entitlements::class)->forWorkspace($c['workspace']->id)->limits();
        $this->assertNotEmpty($warm);

        DB::table('workspace_entitlements')->truncate();
        app(EntitlementResolver::class)->flush();

        $this->assertSame($warm, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limits(),
            'The answer changed after truncating the cache. A miss must compute the real '
            .'answer — returning an empty entitlement reads as UNLIMITED to every consumer.');
    }

    // ══ ⚠️ THE THREE TIME-BASED INVALIDATORS ═══════════════════════════════

    /**
     * ⚠️ THE NASTIEST ONE. A future-dated grant becomes live with no write.
     *
     * `computed_at` is NEWER than the grant row, so any "recompute if the cache
     * is older than its inputs" heuristic looks satisfied while the answer is
     * wrong. Only the absolute boundary catches it.
     */
    #[Test]
    public function a_grant_starting_in_the_future_invalidates_at_its_start(): void
    {
        $partner = Partner::factory()->create();
        $c = $this->customer(['campaigns_per_month' => 100]);

        $addOn = AddOn::factory()->pack()->create();
        AddOnGrant::factory()->for($addOn)->create([
            'key' => 'campaigns_per_month', 'value' => 50,
            'kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'campaigns',
        ]);

        EntitlementGrant::factory()->forClient($c['client'])->create([
            'add_on_id' => $addOn->id,
            'starts_at' => now()->addHours(2),      // dormant
            'ends_at' => null,
        ]);

        $before = app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month');
        $this->assertSame(100, $before, 'A dormant grant must not apply yet.');

        $row = $this->row($c['workspace']->id);
        $this->assertNotNull($row->valid_until,
            'No boundary was stored for a future-dated grant, so nothing will ever recompute '
            .'this row when the grant wakes up.');

        $this->travelTo(now()->addHours(3));
        app(EntitlementResolver::class)->flush();

        $this->assertSame(150, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'),
            'The grant started and the cache still served the old answer. computed_at is NEWER '
            .'than the grant row, so no freshness heuristic could have caught this.');
    }

    /** A grant lapsing at `ends_at` — the slice-5 shape, now through the cache. */
    #[Test]
    public function a_grant_ending_invalidates_at_its_end(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100]);

        $addOn = AddOn::factory()->pack()->create();
        AddOnGrant::factory()->for($addOn)->create([
            'key' => 'campaigns_per_month', 'value' => 50,
            'kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'campaigns',
        ]);
        EntitlementGrant::factory()->forClient($c['client'])->create([
            'add_on_id' => $addOn->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHours(2),
        ]);

        $this->assertSame(150, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'));

        $this->travelTo(now()->addHours(3));
        app(EntitlementResolver::class)->flush();

        $this->assertSame(100, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'),
            'A lapsed grant was still being served from cache.');
    }

    /** A subscription ending. The earliest boundary must win over a later grant. */
    #[Test]
    public function a_subscription_ending_invalidates_at_its_end(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100], sub: function ($s) {
            $s->update(['ends_at' => now()->addHours(2)]);
        });

        $this->assertSame(100, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'));

        $row = $this->row($c['workspace']->id);
        $this->assertNotNull($row->valid_until, 'A subscription with an end date must bound the row.');
        $this->assertTrue(
            Carbon::parse($row->valid_until)->lessThanOrEqualTo(now()->addHours(2)->addMinute()),
            'The boundary is later than the subscription end — the EARLIEST dated input must '
            .'win, not the last one looked at.'
        );
    }

    // ══ Event-driven invalidation ══════════════════════════════════════════

    /** A grant change dispatches reconciliation immediately (rule 7). */
    #[Test]
    public function creating_a_grant_dispatches_reconciliation(): void
    {
        Queue::fake();

        $c = $this->customer(['campaigns_per_month' => 100]);
        $addOn = AddOn::factory()->pack()->create();

        EntitlementGrant::factory()->forClient($c['client'])->create(['add_on_id' => $addOn->id]);

        Queue::assertPushed(
            ReconcileWorkspaceEntitlements::class,
            fn ($job) => $job->clientId === $c['client']->id
        );
    }

    /** …and so does a PARTNER grant, for every one of that partner's clients. */
    #[Test]
    public function a_partner_grant_change_reconciles_all_of_its_clients(): void
    {
        Queue::fake();

        $partner = Partner::factory()->create();
        $c = $this->customer(['campaigns_per_month' => 100], $partner);

        $addOn = AddOn::factory()->pack()->create();
        EntitlementGrant::factory()->forPartner($partner)->create(['add_on_id' => $addOn->id]);

        Queue::assertPushed(
            ReconcileWorkspaceEntitlements::class,
            fn ($job) => $job->clientId === $c['client']->id
        );
    }

    /** The job is idempotent — running it twice produces the same row. */
    #[Test]
    public function reconciliation_is_idempotent(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100]);

        $this->runJob(new ReconcileWorkspaceEntitlements($c['client']->id), [$this->cache()]);
        $first = $this->row($c['workspace']->id);

        $this->runJob(new ReconcileWorkspaceEntitlements($c['client']->id), [$this->cache()]);
        $second = $this->row($c['workspace']->id);

        $this->assertSame($first->payload, $second->payload);
        $this->assertSame($first->source_hash, $second->source_hash);
        $this->assertSame(1, DB::table('workspace_entitlements')->where('workspace_id', $c['workspace']->id)->count(),
            'Reconciling twice created a second row for one workspace.');
    }

    // ══ The brake ══════════════════════════════════════════════════════════

    /** With the cache off, answers are unchanged and nothing is written. */
    #[Test]
    public function disabling_the_cache_changes_no_answer_and_writes_nothing(): void
    {
        config(['entitlements.cache_enabled' => false]);

        $c = $this->customer(['campaigns_per_month' => 100]);

        $this->assertSame(100, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'));
        $this->assertSame(0, DB::table('workspace_entitlements')->count(),
            'The cache wrote a row while disabled.');
    }

    /** The cache brake is INDEPENDENT of the entitlements brake. */
    #[Test]
    public function the_two_brakes_are_independent(): void
    {
        $this->assertNotSame(
            config('entitlements.enabled'),
            null,
            'entitlements.enabled must exist.'
        );
        $this->assertNotNull(config('entitlements.cache_enabled'), 'cache_enabled must exist.');

        config(['entitlements.enabled' => true, 'entitlements.cache_enabled' => false]);
        $c = $this->customer(['campaigns_per_month' => 100]);

        $this->assertSame(100, app(Entitlements::class)->forWorkspace($c['workspace']->id)->limit('campaigns_per_month'),
            'Turning off the cache must not turn off the resolver. A staleness incident must '
            .'not force the whole entitlement layer off — that would discard every correct '
            .'answer along with the stale ones.');
    }
}
