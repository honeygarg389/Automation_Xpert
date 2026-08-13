<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Models\AddOnGrant;
use App\Modules\Entitlements\Models\EntitlementGrant;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\GrantBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE PARTNER CEILING — rule 6, and the vacuity trap that guards it.
 *
 * With no partner grants an intersection produces the same answer as skipping
 * one, so a test that only checks "the number is still right" passes whether the
 * ceiling ran or not. Every test here therefore either:
 *
 *   - asserts the SAME OBJECT INSTANCE came back (the ceiling did not run), or
 *   - uses a partner grant that actually BINDS (a lower number), plus a control
 *     where the partner's grant is HIGHER and must not change anything.
 */
class PartnerCeilingTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): EntitlementResolver
    {
        return app(EntitlementResolver::class);
    }

    /** A client on a plan, optionally under a partner. */
    private function customer(array $limits, ?Partner $partner = null): array
    {
        $plan = Plan::factory()->create(['limits' => $limits]);
        $client = Client::factory()->create(['partner_id' => $partner?->id]);

        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['client' => $client->refresh(), 'workspace' => $workspace];
    }

    /** Give a partner a package granting `$grants`, and put it in ceiling mode. */
    private function ceilingPartner(array $grants, array $flags = []): Partner
    {
        $partner = Partner::factory()->create();

        $addOn = AddOn::factory()->package(50)->create();
        foreach ($grants as $key => $value) {
            AddOnGrant::factory()->for($addOn)->create([
                'key' => $key, 'value' => $value, 'kind' => AddOnGrant::KIND_COUNTER, 'unit' => 'messages',
            ]);
        }
        foreach ($flags as $key) {
            AddOnGrant::factory()->for($addOn)->boolean($key)->create();
        }

        EntitlementGrant::factory()->forPartner($partner)->create(['add_on_id' => $addOn->id]);

        $partner->update(['entitlement_mode' => Partner::MODE_CEILING]);

        return $partner->refresh();
    }

    // ══ ⚠️ DIRECT CUSTOMERS — the majority path, PROVEN untouched ══════════

    /**
     * The strongest available proof that the ceiling did not run: the resolver
     * returns the IDENTICAL object, not an equal one.
     *
     * An intersection that happens to be a no-op would build a new Entitlement
     * and fail this. That is the difference between "the answer is unchanged"
     * and "the code did not execute".
     */
    #[Test]
    public function a_direct_customer_gets_the_identical_entitlement_instance(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100]);

        $this->assertNull($c['client']->partner_id, 'Precondition: a direct customer.');

        $resolver = $this->resolver();
        $folded = $resolver->fold([
            new GrantBundle(AddOn::TYPE_PACKAGE, grants: ['campaigns_per_month' => 100]),
        ]);

        $this->assertSame($folded, $resolver->applyCeiling($folded, null),
            'applyCeiling built a new Entitlement for a customer with no partner. It must '
            .'return the same instance — anything else means the ceiling code ran.');
    }

    /** …and the resolved values are exactly the plan's. */
    #[Test]
    public function a_direct_customers_limits_are_exactly_their_plans(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100, 'chatbots' => 5]);
        $e = $this->resolver()->forClient($c['client']);

        $this->assertSame(100, $e->limit('campaigns_per_month'));
        $this->assertSame(5, $e->limit('chatbots'));
    }

    /**
     * ⚠️ No PARTNER-side lookup is issued for a direct customer.
     *
     * Without this, partner resolution could run and return nothing, and the
     * answer would be right for the wrong reason — which is how a later change
     * to the "nothing" case would silently start affecting direct customers.
     *
     * ─── This assertion was originally too broad ────────────────────────────
     *
     * It first asserted that resolving a direct customer touched
     * `entitlement_grants` AT ALL. That was wrong, and slice 7 proved it: a
     * direct customer can legitimately HOLD grants of their own — client_id set,
     * partner_id null — and the resolver must read them. The intent was always
     * "the partner ceiling must not run", so it now asserts on the partner
     * predicate rather than on the table name.
     *
     * The over-broad version passed only because customer-held grants were, at
     * the time, read by nobody.
     */
    #[Test]
    public function resolving_a_direct_customer_issues_no_partner_side_lookup(): void
    {
        $c = $this->customer(['campaigns_per_month' => 100]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->resolver()->forClient($c['client']);

        $partnerSide = array_filter(
            $queries,
            fn ($sql) => str_contains($sql, 'entitlement_grants') && str_contains($sql, 'partner_id')
        );

        $this->assertSame([], array_values($partnerSide),
            'A direct customer caused a PARTNER-grant lookup. Direct customers are the majority '
            .'path and must not pay for, or be affected by, the partner tier.');

        // POSITIVE CONTROL: the client side IS read, so the filter above is
        // narrowing something real rather than matching nothing.
        $clientSide = array_filter(
            $queries,
            fn ($sql) => str_contains($sql, 'entitlement_grants') && str_contains($sql, 'client_id')
        );

        $this->assertNotEmpty($clientSide,
            'No client-grant lookup happened either, so the assertion above is vacuous — it '
            .'would pass against a resolver that reads no grants at all.');
    }

    // ══ unrestricted mode ══════════════════════════════════════════════════

    /** A partner NOT in ceiling mode leaves its customers completely alone. */
    #[Test]
    public function an_unrestricted_partner_does_not_cap_its_customers(): void
    {
        $partner = Partner::factory()->create();
        $this->assertSame(Partner::MODE_UNRESTRICTED, $partner->entitlement_mode);

        $c = $this->customer(['campaigns_per_month' => 100], $partner);

        $this->assertSame(100, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'An unrestricted partner capped its customer. unrestricted must SKIP the ceiling '
            .'entirely, not intersect with an empty one — that distinction is the whole of R-1.');
    }

    // ══ ceiling mode — the intersection ════════════════════════════════════

    /** ⚠️ The headline: a lower partner grant CAPS the customer. */
    #[Test]
    public function a_lower_partner_ceiling_caps_the_customer(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        $this->assertSame(1000, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'The customer kept their plan value of 5000 above a partner ceiling of 1000. A '
            .'reseller cannot sell what it does not hold.');
    }

    /** POSITIVE CONTROL: a HIGHER partner grant changes nothing. */
    #[Test]
    public function a_higher_partner_ceiling_leaves_the_customer_alone(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 9000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        $this->assertSame(5000, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'The ceiling reduced a customer below their plan when the partner held MORE. If '
            .'this fails the intersection is taking the wrong side, and the test above would '
            .'still have passed.');
    }

    /**
     * ⚠️ A partner grant of null means UNLIMITED and must not cap.
     *
     * PHP's own min(null, 5) returns null, which reads as unlimited — so a naive
     * implementation turns a real ceiling into no ceiling. This is the inversion
     * the explicit comparison exists to prevent.
     */
    #[Test]
    public function a_null_partner_grant_is_unlimited_and_does_not_cap(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => null]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        $this->assertSame(5000, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'A partner holding this unlimited must not reduce the customer.');
    }

    /** …and an unlimited CUSTOMER is capped by a bounded partner. */
    #[Test]
    public function an_unlimited_customer_is_capped_by_a_bounded_partner(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 200]);
        $c = $this->customer(['campaigns_per_month' => null], $partner);

        $this->assertSame(200, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'An unlimited plan under a bounded partner stayed unlimited — the reseller would be '
            .'selling more than it holds.');
    }

    /**
     * ⚠️ A key the ceiling never mentions is NOT resellable.
     *
     * Per the ruling: absence is not "unrestricted". A partner holding a grant
     * for campaigns and nothing for chatbots cannot resell chatbots.
     */
    #[Test]
    public function a_key_absent_from_the_ceiling_is_not_granted(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000, 'chatbots' => 10], $partner);

        $e = $this->resolver()->forClient($c['client']);

        $this->assertSame(1000, $e->limit('campaigns_per_month'));
        $this->assertFalse($e->has('chatbots'),
            'chatbots survived a ceiling that does not mention it. Absence must mean "the '
            .'partner does not hold this", not "no cap".');
        $this->assertNull($e->limit('chatbots'));
    }

    /** Booleans AND together. */
    #[Test]
    public function feature_flags_are_anded_against_the_ceiling(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000], flags: ['white_label']);

        $c = $this->customer(['campaigns_per_month' => 100], $partner);
        $customerEntitlement = $this->resolver()->forClient($c['client']);

        // The customer's plan grants no flags today, so the AND is false — and
        // that is the correct direction: a partner flag alone does not grant.
        $this->assertFalse($customerEntitlement->allows('white_label'),
            'A partner holding white_label granted it to a customer whose plan does not. The '
            .'ceiling caps; it does not confer.');
    }

    // ══ white-label: the grant is authoritative, the column is the seed ═════

    /**
     * ⚠️ `plans.white_label_enabled` is now a SEED, not a decision.
     *
     * The column has no partner dimension, so rule 6 was unenforceable for it: a
     * partner who may not white-label could not cap a customer whose plan flag
     * was true. Bridged into the synthesized package as a feature grant, it
     * becomes something the ceiling can intersect.
     */
    #[Test]
    public function the_legacy_white_label_column_is_bridged_into_the_entitlement(): void
    {
        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 10], 'white_label_enabled' => true]);
        $client = Client::factory()->create();
        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $this->assertTrue($this->resolver()->forClient($client)->allows('white_label'),
            'A plan with white_label_enabled did not confer the white_label entitlement. The '
            .'column is the legacy seed and the resolver is the authority; if the bridge is '
            .'gone the column decides nothing and nothing else does either.');
    }

    /** POSITIVE CONTROL: a plan without the flag confers nothing. */
    #[Test]
    public function a_plan_without_the_legacy_flag_does_not_confer_white_label(): void
    {
        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 10], 'white_label_enabled' => false]);
        $client = Client::factory()->create();
        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $this->assertFalse($this->resolver()->forClient($client)->allows('white_label'),
            'white_label was granted by a plan that does not have it — the bridge is returning '
            .'true unconditionally, and the test above would pass regardless.');
    }

    /**
     * ⚠️ And now it can be CAPPED, which is the entire reason for the bridge.
     *
     * A partner in ceiling mode that does not hold white_label must strip it
     * from a customer whose plan grants it. This was impossible while a boolean
     * column on `plans` was the authority.
     */
    #[Test]
    public function a_ceiling_partner_without_white_label_strips_it_from_the_customer(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);   // no white_label grant

        $plan = Plan::factory()->create(['limits' => ['campaigns_per_month' => 100], 'white_label_enabled' => true]);
        $client = Client::factory()->create(['partner_id' => $partner->id]);
        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $this->assertFalse($this->resolver()->forClient($client->refresh())->allows('white_label'),
            'A partner that does not hold white_label resold it. This is exactly what a boolean '
            .'column on plans could never express, and why the grant is authoritative.');
    }

    // ══ ⚠️ THE READ-TIME INVARIANT — ceiling mode, no grants ═══════════════

    /**
     * ⚠️ THE TEST THE RULING ASKED FOR, and the answer must never be unlimited.
     *
     * Create a ceiling partner WITH grants, then delete them. The partner row is
     * untouched, so `Partner::assertCeilingIsConfigured()` never fires — the
     * save-time rule cannot see this at all.
     */
    #[Test]
    public function a_ceiling_partner_whose_grants_are_deleted_grants_nothing(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        $this->assertSame(1000, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'),
            'Precondition: the ceiling binds while the grant exists.');

        DB::table('entitlement_grants')->where('partner_id', $partner->id)->delete();
        $partner->refresh();

        $this->assertSame(Partner::MODE_CEILING, $partner->entitlement_mode,
            'The partner row is untouched — which is exactly why the save-time rule cannot '
            .'catch this.');

        $e = app(EntitlementResolver::class)->forClient($c['client']->refresh());

        $this->assertFalse($e->has('campaigns_per_month'),
            'A ceiling partner with no grants granted something. Whatever the answer is, it '
            .'must not be unlimited — an empty ceiling in ceiling mode permits nothing.');
        $this->assertSame([], $e->limits(), 'Nothing at all should survive an empty ceiling.');
    }

    /**
     * ⚠️ THE SHARPER CASE: the grant LAPSES BY DATE.
     *
     * Nothing is written when `ends_at` passes. No hook on Partner, and no hook
     * on grant deletion, can observe this — the row simply stops being in force
     * while sitting still. This is why the invariant has to hold at read time.
     */
    #[Test]
    public function a_ceiling_partner_whose_grant_has_lapsed_grants_nothing(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        $this->assertSame(1000, $this->resolver()->forClient($c['client'])->limit('campaigns_per_month'));

        // Not a deletion, not a status change — just time passing.
        DB::table('entitlement_grants')->where('partner_id', $partner->id)
            ->update(['ends_at' => now()->subDay()]);

        $e = app(EntitlementResolver::class)->forClient($c['client']->refresh());

        $this->assertSame([], $e->limits(),
            'A lapsed grant still acted as a ceiling, or worse, granted unlimited. `ends_at` '
            .'passing writes nothing, so no save-time rule can ever catch it.');
        $this->assertFalse($e->has('campaigns_per_month'));
    }

    /** …and it is logged, because it is a misconfiguration rather than a state. */
    #[Test]
    public function an_empty_ceiling_is_logged(): void
    {
        $logged = [];
        Event::listen(
            MessageLogged::class,
            function ($e) use (&$logged) {
                $logged[] = $e->message;
            }
        );

        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);
        DB::table('entitlement_grants')->where('partner_id', $partner->id)->delete();

        app(EntitlementResolver::class)->forClient($c['client']->refresh());

        $this->assertNotEmpty(
            array_filter($logged, fn ($m) => str_contains((string) $m, 'entitlements.ceiling_empty')),
            'An empty ceiling silently entitled a partner’s customers to nothing. It must say '
            .'so — the customers cannot tell a misconfiguration from a plan change.'
        );
    }

    /** POSITIVE CONTROL: a still-valid grant is not treated as lapsed. */
    #[Test]
    public function a_grant_with_a_future_end_date_still_acts_as_a_ceiling(): void
    {
        $partner = $this->ceilingPartner(['campaigns_per_month' => 1000]);
        $c = $this->customer(['campaigns_per_month' => 5000], $partner);

        DB::table('entitlement_grants')->where('partner_id', $partner->id)
            ->update(['ends_at' => now()->addYear()]);

        $this->assertSame(1000, app(EntitlementResolver::class)->forClient($c['client'])->limit('campaigns_per_month'),
            'A grant valid for another year was treated as lapsed — the in-force check is '
            .'rejecting everything, which would make the two tests above pass for free.');
    }
}
