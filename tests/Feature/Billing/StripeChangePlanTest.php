<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Stripe changePlan: the minimum-difference guard and the error-message cleanup.
 *
 * StripeGateway talks through the official SDK, not the Http facade, so
 * Http::fake() cannot see it. `client()` is `protected` — the deliberate seam —
 * so a subclass injects a client that throws, and the guard tests need no client
 * at all because they return before it is touched.
 */
class StripeChangePlanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Subscription, 1: Plan, 2: Plan} */
    private function scenario(int $currentCents = 10000, int $newCents = 50000): array
    {
        $currentPlan = Plan::factory()->create(['monthly_price_cents' => $currentCents]);
        $newPlan = Plan::factory()->create([
            'monthly_price_cents' => $newCents,
            'stripe_monthly_id' => 'price_NEW',
        ]);
        $user = User::factory()->create(['role' => 'client']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $currentPlan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_STRIPE',
            'starts_at' => now()->subMonth(),
        ]);

        return [$subscription, $currentPlan, $newPlan];
    }

    /** A gateway whose Stripe client always throws, to exercise the catch path. */
    private function throwingGateway(string $message): StripeGateway
    {
        return new class('sk_test', '', 'http://s', 'http://c', $message) extends StripeGateway
        {
            public function __construct(
                string $secret,
                string $webhookSecret,
                string $success,
                string $cancel,
                private string $boom
            ) {
                parent::__construct($secret, $webhookSecret, $success, $cancel);
            }

            protected function client(): StripeClient
            {
                throw new \RuntimeException($this->boom);
            }
        };
    }

    /**
     * ⚠️ The point of the guard is that it spends nothing — it must return before
     * the Stripe client is even constructed. Using the throwing gateway proves
     * that: if the guard did not fire first, the exception would surface instead.
     */
    #[Test]
    public function a_sub_threshold_difference_is_refused_before_stripe_is_touched(): void
    {
        [$subscription, , $newPlan] = $this->scenario(currentCents: 10000, newCents: 10049);

        $result = $this->throwingGateway('client should never be built')
            ->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('too close', $result['error']);
        $this->assertStringNotContainsString('client should never be built', $result['error']);
    }

    #[Test]
    public function a_difference_of_exactly_the_minimum_passes_the_guard(): void
    {
        [$subscription, , $newPlan] = $this->scenario(currentCents: 10000, newCents: 10050);

        $result = $this->throwingGateway('reached the client')
            ->changePlan($subscription, $newPlan, 'month');

        // It fails — but on the API path, which proves the guard let it through.
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('too close', $result['error']);
    }

    /**
     * ⚠️ THE REGRESSION THIS PROTECTS: the raw exception text used to be returned
     * verbatim and flashed to the customer's browser.
     */
    #[Test]
    public function a_stripe_exception_is_not_leaked_to_the_caller(): void
    {
        [$subscription, , $newPlan] = $this->scenario();

        $raw = 'No such price: price_NEW; a similar object exists in test mode (req_9xKq2)';
        $result = $this->throwingGateway($raw)->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('No such price', $result['error']);
        $this->assertStringNotContainsString('req_9xKq2', $result['error']);
        $this->assertStringNotContainsString('price_NEW', $result['error']);
        $this->assertSame('Could not change your plan. Please try again or contact support.', $result['error']);
    }

    #[Test]
    public function a_missing_price_id_is_still_refused_with_its_own_message(): void
    {
        [$subscription, , $newPlan] = $this->scenario();
        $newPlan->update(['stripe_monthly_id' => null]);

        $result = $this->throwingGateway('unused')->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not have a Stripe price configured', $result['error']);
    }

    /** Verification failure must be reported as such, never as a mismatch. */
    #[Test]
    public function price_verification_reports_failure_distinctly(): void
    {
        $result = $this->throwingGateway('network down')->priceAmountMinorUnits('price_X');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('amount', $result);
    }
}
