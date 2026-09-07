<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CashfreeGateway;
use App\Services\Billing\PayPalGateway;
use App\Services\Billing\RazorpayGateway;
use App\Services\Billing\StripeGateway;
use App\Support\BillingCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Each gateway must send the RIGHT interval for a quarterly / half-yearly plan.
 *
 * ⚠️ WHY THIS IS ASSERTED ON THE OUTBOUND REQUEST, NOT A RETURN VALUE.
 *
 * The previous ternaries returned success for a quarterly checkout — they simply
 * created a MONTHLY plan at the gateway while telling the caller everything was
 * fine. No return value, status code or exception distinguished the two. The
 * only artefact that differs is the request body, so that is what is pinned.
 */
class GatewayBillingCycleTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::factory()->create([
            'currency_code' => 'INR',
            'monthly_price_cents' => 10000,
            'quarterly_price_cents' => 27000,
            'half_yearly_price_cents' => 51000,
            'yearly_price_cents' => 96000,
            'stripe_monthly_id' => 'price_MONTH',
            'stripe_quarterly_id' => 'price_QUARTER',
            'stripe_half_yearly_id' => 'price_HALF',
            'stripe_yearly_id' => 'price_YEAR',
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'client']);
    }

    // ── Razorpay ──────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     *                                                                    cycle, expected period, expected interval, expected total_count
     */
    public static function razorpayCycles(): array
    {
        return [
            'monthly' => ['month', 'monthly', 1, 120],
            // ⚠️ native quarterly — not monthly x 3
            'quarterly' => ['quarter', 'quarterly', 1, 40],
            'half-yearly' => ['half_year', 'monthly', 6, 20],
            'yearly' => ['year', 'yearly', 1, 10],
        ];
    }

    #[Test]
    #[DataProvider('razorpayCycles')]
    public function razorpay_sends_the_right_period_and_horizon(string $cycle, string $period, int $interval, int $totalCount): void
    {
        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_X'], 200),
            'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_X', 'short_url' => 'https://rzp.io/x'], 200),
        ]);

        $gateway = new RazorpayGateway('key', 'secret', 'whsec');
        $result = $gateway->createCheckout($this->user(), $this->plan(), $cycle);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/plans'
            && $r['period'] === $period
            && $r['interval'] === $interval);

        Http::assertSent(fn ($r) => $r->url() === 'https://api.razorpay.com/v1/subscriptions'
            && $r['total_count'] === $totalCount);
    }

    // ── Cashfree ──────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function cashfreeCycles(): array
    {
        return [
            'monthly' => ['month', 'MONTH', 1, 120],
            'quarterly' => ['quarter', 'MONTH', 3, 40],
            'half-yearly' => ['half_year', 'MONTH', 6, 20],
            'yearly' => ['year', 'YEAR', 1, 10],
        ];
    }

    #[Test]
    #[DataProvider('cashfreeCycles')]
    public function cashfree_sends_the_right_interval_type_and_multiplier(string $cycle, string $type, int $intervals, int $maxCycles): void
    {
        Http::fake(['*' => Http::response(['subscription_session_id' => 'sess_X'], 200)]);

        $gateway = new CashfreeGateway('cid', 'csecret', true, 'http://return');
        $result = $gateway->createCheckout($this->user(), $this->plan(), $cycle);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');

        Http::assertSent(fn ($r) => isset($r['plan_details'])
            && $r['plan_details']['plan_interval_type'] === $type
            && $r['plan_details']['plan_intervals'] === $intervals
            && $r['plan_details']['plan_max_cycles'] === $maxCycles);
    }

    // ── PayPal ────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function paypalCycles(): array
    {
        return [
            'monthly' => ['month', 'MONTH', 1],
            'quarterly' => ['quarter', 'MONTH', 3],
            'half-yearly' => ['half_year', 'MONTH', 6],
            'yearly' => ['year', 'YEAR', 1],
        ];
    }

    #[Test]
    #[DataProvider('paypalCycles')]
    public function paypal_sends_the_right_frequency(string $cycle, string $unit, int $count): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            '*/v1/catalogs/products' => Http::response(['id' => 'PROD-1'], 200),
            '*/v1/billing/plans' => Http::response(['id' => 'P-1'], 200),
            '*/v1/billing/subscriptions' => Http::response([
                'id' => 'I-1',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
            ], 200),
        ]);

        $gateway = new PayPalGateway('cid', 'secret', true, 'http://s', 'http://c', '');
        $gateway->createCheckout($this->user(), $this->plan(), $cycle);

        Http::assertSent(function ($r) use ($unit, $count) {
            if (! str_contains($r->url(), '/v1/billing/plans')) {
                return false;
            }
            $freq = $r['billing_cycles'][0]['frequency'] ?? [];

            return ($freq['interval_unit'] ?? null) === $unit
                && ($freq['interval_count'] ?? null) === $count;
        });
    }

    // ── Stripe: the flagged price-id bug ──────────────────────────────────

    /**
     * ⚠️ THE HIGHEST-SEVERITY FINDING OF THE INVESTIGATION.
     *
     * `changePlan()` resolved the price id with
     * `$cycle === 'year' ? stripe_yearly_id : stripe_monthly_id`. A quarterly
     * change therefore found the MONTHLY price id — which exists on this plan —
     * and would have moved the customer to monthly billing while returning
     * success. Nothing errored, so nothing would have surfaced it.
     */
    #[Test]
    public function stripe_change_plan_uses_the_price_id_for_the_requested_cycle(): void
    {
        $plan = $this->plan();
        $user = $this->user();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_S',
            'starts_at' => now()->subMonth(),
        ]);

        foreach ([
            'quarter' => 'price_QUARTER',
            'half_year' => 'price_HALF',
            'year' => 'price_YEAR',
            'month' => 'price_MONTH',
        ] as $cycle => $expectedPriceId) {
            $gateway = $this->recordingStripeGateway();
            $gateway->changePlan($subscription, $plan, $cycle);

            $this->assertSame($expectedPriceId, $gateway->seenPriceId,
                "changePlan('{$cycle}') resolved '{$gateway->seenPriceId}' — it must use {$expectedPriceId}, "
                .'not fall through to the monthly price id.');
        }
    }

    #[Test]
    public function stripe_change_plan_refuses_an_unknown_cycle_instead_of_defaulting(): void
    {
        $plan = $this->plan();
        $subscription = Subscription::create([
            'user_id' => $this->user()->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_S2',
            'starts_at' => now()->subMonth(),
        ]);

        $gateway = $this->recordingStripeGateway();
        $result = $gateway->changePlan($subscription, $plan, 'fortnightly');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Unsupported billing cycle', $result['error']);
        $this->assertNull($gateway->seenPriceId, 'It reached the price lookup for an unknown cycle.');
    }

    /**
     * ⚠️ A NAMED SUBCLASS, NOT AN ANONYMOUS ONE.
     *
     * An anonymous class cannot be named in a return type, so the factory either
     * declares `: StripeGateway` — which erases `$seenPriceId` and makes every
     * read an undefined-property error — or declares nothing, which is a
     * missing-return-type error. A named class has neither problem and reads
     * better at the call site.
     */
    private function recordingStripeGateway(): RecordingStripeGateway
    {
        return new RecordingStripeGateway('sk_test', '', 'http://s', 'http://c');
    }
}

/**
 * Captures the price id `changePlan()` resolves, then throws before any network
 * call — `client()` is `protected`, which is the seam this relies on.
 */
class RecordingStripeGateway extends StripeGateway
{
    public ?string $seenPriceId = null;

    protected function client(): StripeClient
    {
        throw new \RuntimeException('no network in tests');
    }

    /** @return array{ok: bool, error: string|null} */
    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        $column = BillingCycle::stripePriceIdColumn($billingCycle);
        $this->seenPriceId = $column ? $newPlan->{$column} : null;

        return parent::changePlan($subscription, $newPlan, $billingCycle);
    }
}
