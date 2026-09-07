<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\BillingCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cycle vocabulary and its gateway mappings.
 *
 * ⚠️ These assertions are deliberately about the MAP, not about any gateway's
 * behaviour. Eight binary ternaries used to derive intervals, and every one of
 * them FAILED OPEN — an unrecognised cycle fell into the `month` branch, so a
 * quarterly plan would have been created, charged and renewed monthly with no
 * error raised anywhere. The map is the fix, so the map is what is pinned.
 */
class BillingCycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_cycle_has_a_complete_mapping_for_every_gateway(): void
    {
        $this->assertSame(['month', 'quarter', 'half_year', 'year'], BillingCycle::ALL);

        foreach (BillingCycle::ALL as $cycle) {
            $this->assertNotNull(BillingCycle::stripe($cycle), "stripe mapping missing for {$cycle}");
            $this->assertNotNull(BillingCycle::razorpay($cycle), "razorpay mapping missing for {$cycle}");
            $this->assertNotNull(BillingCycle::cashfree($cycle), "cashfree mapping missing for {$cycle}");
            $this->assertNotNull(BillingCycle::paypal($cycle), "paypal mapping missing for {$cycle}");
            $this->assertNotNull(BillingCycle::horizonCycles($cycle), "horizon missing for {$cycle}");
            $this->assertNotNull(BillingCycle::priceColumn($cycle), "price column missing for {$cycle}");
            $this->assertNotNull(BillingCycle::stripePriceIdColumn($cycle), "stripe id column missing for {$cycle}");
            $this->assertNotNull(BillingCycle::months($cycle), "months missing for {$cycle}");
        }
    }

    /**
     * ⚠️ THE FAIL-CLOSED PROPERTY. An unknown cycle must return null so callers
     * refuse, never a default that silently bills the wrong interval.
     */
    #[Test]
    public function an_unknown_cycle_maps_to_null_everywhere_rather_than_defaulting(): void
    {
        $this->assertFalse(BillingCycle::isValid('fortnightly'));
        $this->assertNull(BillingCycle::stripe('fortnightly'));
        $this->assertNull(BillingCycle::razorpay('fortnightly'));
        $this->assertNull(BillingCycle::cashfree('fortnightly'));
        $this->assertNull(BillingCycle::paypal('fortnightly'));
        $this->assertNull(BillingCycle::horizonCycles('fortnightly'));
        $this->assertNull(BillingCycle::stripePriceIdColumn('fortnightly'));
        $this->assertNull(BillingCycle::toClientVocabulary('fortnightly'));
        $this->assertNull(BillingCycle::fromClientVocabulary('fortnightly'));
    }

    /** The exact vendor field values, per gateway. */
    #[Test]
    public function the_gateway_field_values_match_each_vendors_api(): void
    {
        // Stripe: no native quarter/half-year — month multiples.
        $this->assertSame(['interval' => 'month', 'interval_count' => 3], BillingCycle::stripe('quarter'));
        $this->assertSame(['interval' => 'month', 'interval_count' => 6], BillingCycle::stripe('half_year'));
        $this->assertSame(['interval' => 'year', 'interval_count' => 1], BillingCycle::stripe('year'));

        // ⚠️ Razorpay is the ONLY one with a native quarterly period.
        $this->assertSame(['period' => 'quarterly', 'interval' => 1], BillingCycle::razorpay('quarter'));
        $this->assertSame(['period' => 'monthly', 'interval' => 6], BillingCycle::razorpay('half_year'));

        // Cashfree: DAY|WEEK|MONTH|YEAR only.
        $this->assertSame(['type' => 'MONTH', 'intervals' => 3], BillingCycle::cashfree('quarter'));
        $this->assertSame(['type' => 'MONTH', 'intervals' => 6], BillingCycle::cashfree('half_year'));

        // PayPal: MONTH with an interval_count (capped at 12 by the vendor).
        $this->assertSame(['interval_unit' => 'MONTH', 'interval_count' => 3], BillingCycle::paypal('quarter'));
        $this->assertSame(['interval_unit' => 'MONTH', 'interval_count' => 6], BillingCycle::paypal('half_year'));
    }

    /**
     * ⚠️ HORIZONS ARE CYCLES, NOT MONTHS — the bug the old `? 10 : 120` would
     * have produced. Every row must multiply out to the same ~10 years.
     */
    #[Test]
    public function every_horizon_is_the_same_ten_year_span(): void
    {
        foreach (BillingCycle::ALL as $cycle) {
            $years = (BillingCycle::horizonCycles($cycle) * BillingCycle::months($cycle)) / 12;
            $this->assertSame(10.0, (float) $years,
                "{$cycle}'s horizon is {$years} years, not 10 — the count is in CYCLES, not months.");
        }
    }

    #[Test]
    public function the_two_table_vocabularies_round_trip(): void
    {
        $expected = ['month' => 'monthly', 'quarter' => 'quarterly', 'half_year' => 'half_yearly', 'year' => 'yearly'];

        foreach ($expected as $short => $long) {
            $this->assertSame($long, BillingCycle::toClientVocabulary($short));
            $this->assertSame($short, BillingCycle::fromClientVocabulary($long));
        }
    }

    // ── Plan::priceCentsForCycle ──────────────────────────────────────────

    #[Test]
    public function a_plan_prices_each_cycle_from_its_own_column(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 999,
            'monthly_price_cents' => 1000,
            'quarterly_price_cents' => 2700,
            'half_yearly_price_cents' => 5100,
            'yearly_price_cents' => 9600,
        ]);

        $this->assertSame(1000, $plan->priceCentsForCycle('month'));
        $this->assertSame(2700, $plan->priceCentsForCycle('quarter'));
        $this->assertSame(5100, $plan->priceCentsForCycle('half_year'));
        $this->assertSame(9600, $plan->priceCentsForCycle('year'));
    }

    /**
     * ⚠️ ONLY `month` FALLS BACK TO price_cents.
     *
     * price_cents is the legacy single-price column and means "the monthly
     * price". If quarter or half_year inherited that fallback, a plan not sold
     * quarterly would silently be sold at the MONTHLY price for three months of
     * service.
     */
    #[Test]
    public function only_month_falls_back_to_the_legacy_price_column(): void
    {
        $plan = Plan::factory()->create([
            'price_cents' => 4200,
            'monthly_price_cents' => null,
            'quarterly_price_cents' => null,
            'half_yearly_price_cents' => null,
            'yearly_price_cents' => null,
        ]);

        $this->assertSame(4200, $plan->priceCentsForCycle('month'), 'month should fall back to price_cents');
        $this->assertNull($plan->priceCentsForCycle('quarter'), 'quarter must NOT inherit the monthly fallback');
        $this->assertNull($plan->priceCentsForCycle('half_year'), 'half_year must NOT inherit the monthly fallback');
        $this->assertNull($plan->priceCentsForCycle('year'));
    }

    /**
     * ⚠️ THE NULL-PRICE BUG. `Client\SubscriptionController` sent
     * `$p->monthly_price` and `$p->annual_price` — neither a column nor an
     * accessor on Plan, so both resolved to null and the change-plan modal
     * listed every plan with no price at all. It failed silently because a
     * missing Eloquent attribute is null, not an error.
     */
    #[Test]
    public function the_change_plan_page_sends_real_prices_for_every_cycle(): void
    {
        $plan = Plan::factory()->create([
            'enabled' => true,
            'monthly_price_cents' => 1000,
            'quarterly_price_cents' => 2700,
            'half_yearly_price_cents' => null,
            'yearly_price_cents' => 9600,
        ]);

        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_prices',
            'starts_at' => now()->subMonth(),
        ]);

        $this->actingAs($user)
            ->get(route('client.subscription.show'))
            ->assertInertia(function ($page) use ($plan) {
                // Plain array search: collect() on an untyped Inertia prop
                // cannot resolve its template types under PHPStan L6.
                $plans = $page->toArray()['props']['plans'];
                $row = null;
                foreach ($plans as $candidate) {
                    if (($candidate['id'] ?? null) === $plan->id) {
                        $row = $candidate;
                        break;
                    }
                }

                $this->assertNotNull($row, 'the plan was not sent to the page');
                $this->assertArrayNotHasKey('monthly_price', $row,
                    'the non-existent monthly_price property is still being sent');
                $this->assertSame(1000, $row['prices_cents']['month']);
                $this->assertSame(2700, $row['prices_cents']['quarter']);
                $this->assertNull($row['prices_cents']['half_year']);
                $this->assertSame(9600, $row['prices_cents']['year']);
                $this->assertSame(['month', 'quarter', 'year'], $row['available_cycles']);
            });
    }

    /** A cycle with no price must not be offered to a customer. */
    #[Test]
    public function available_cycles_lists_only_priced_cycles(): void
    {
        $plan = Plan::factory()->create([
            'monthly_price_cents' => 1000,
            'quarterly_price_cents' => null,
            'half_yearly_price_cents' => 5100,
            'yearly_price_cents' => 0,
        ]);

        $this->assertSame(['month', 'half_year'], $plan->availableCycles(),
            'A null price and a zero price both mean "not sold on this cycle".');
    }
}
