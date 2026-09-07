<?php

namespace Tests\Feature\Billing;

use App\Events\PlanChanged;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\RazorpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Razorpay in-place plan change.
 *
 * Http::fake() rather than a mock object: the gateway builds its calls through
 * the Http facade (`private http()`), so faking at that layer exercises the real
 * request-building code — the URL, the body and the auth are all asserted as
 * they would actually be sent.
 *
 * ⚠️ Every success case asserts the STORED ROW, not just the dispatched request.
 * A test that only checks what was sent passes while the local update writes
 * nothing — the mass-assignment trap this codebase has already been bitten by.
 */
class RazorpayChangePlanTest extends TestCase
{
    use RefreshDatabase;

    private function gateway(): RazorpayGateway
    {
        return new RazorpayGateway('rzp_test_key', 'rzp_test_secret', 'whsec');
    }

    /** @return array{0: Subscription, 1: Plan, 2: Plan} */
    private function scenario(int $currentCents = 10000, int $newCents = 50000): array
    {
        $currentPlan = Plan::factory()->create(['monthly_price_cents' => $currentCents, 'currency_code' => 'INR']);
        $newPlan = Plan::factory()->create(['monthly_price_cents' => $newCents, 'currency_code' => 'INR']);
        $user = User::factory()->create(['role' => 'client']);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $currentPlan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_ABC123',
            'starts_at' => now()->subMonth(),
        ]);

        return [$subscription, $currentPlan, $newPlan];
    }

    #[Test]
    public function a_successful_change_patches_razorpay_and_updates_the_local_row(): void
    {
        Event::fake([PlanChanged::class]);
        [$subscription, , $newPlan] = $this->scenario();

        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_NEW999'], 200),
            'api.razorpay.com/v1/subscriptions/*' => Http::response(['id' => 'sub_ABC123', 'status' => 'active'], 200),
        ]);

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'month');

        $this->assertTrue($result['ok'], 'changePlan reported failure: '.($result['error'] ?? ''));

        // The PATCH: right verb, right URL, right body.
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.razorpay.com/v1/subscriptions/sub_ABC123'
                && $request['plan_id'] === 'plan_NEW999'
                && $request['schedule_change_at'] === 'now';
        });

        // ⚠️ total_count is not an accepted parameter on this endpoint.
        Http::assertSent(fn ($request) => $request->method() !== 'PATCH' || ! isset($request['total_count']));

        // The stored row, not the payload.
        $subscription->refresh();
        $this->assertSame($newPlan->id, $subscription->plan_id);
        $this->assertSame('month', $subscription->billing_cycle);
        $this->assertSame('plan_NEW999', $subscription->gateway_metadata['razorpay_plan_id'] ?? null,
            'The new Razorpay plan id was not persisted to gateway_metadata.');

        Event::assertDispatched(PlanChanged::class);
    }

    #[Test]
    public function a_razorpay_rejection_surfaces_its_description_and_changes_nothing(): void
    {
        Event::fake([PlanChanged::class]);
        [$subscription, $currentPlan, $newPlan] = $this->scenario();

        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_NEW999'], 200),
            'api.razorpay.com/v1/subscriptions/*' => Http::response([
                'error' => ['description' => 'Subscriptions cannot be updated when payment mode is UPI'],
            ], 400),
        ]);

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertSame('Subscriptions cannot be updated when payment mode is UPI', $result['error']);

        $subscription->refresh();
        $this->assertSame($currentPlan->id, $subscription->plan_id, 'The local row was updated despite the API rejecting the change.');
        $this->assertArrayNotHasKey('razorpay_plan_id', $subscription->gateway_metadata ?? [],
            'A Razorpay plan id was persisted even though the change was rejected.');

        Event::assertNotDispatched(PlanChanged::class);
    }

    /**
     * ⚠️ Asserts NO REQUEST IS MADE. Returning an error is not enough — the point
     * of a pre-flight guard is that it spends nothing, and a guard that still
     * created a Razorpay plan object would leave orphans behind on every refusal.
     */
    #[Test]
    public function a_sub_threshold_difference_is_refused_without_calling_razorpay(): void
    {
        Event::fake([PlanChanged::class]);
        // 49 paise apart — one under the documented 50-subunit minimum.
        [$subscription, , $newPlan] = $this->scenario(currentCents: 10000, newCents: 10049);

        Http::fake();

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('too close', $result['error']);

        Http::assertNothingSent();
        Event::assertNotDispatched(PlanChanged::class);
    }

    /** The boundary itself: exactly 50 subunits must be allowed through. */
    #[Test]
    public function a_difference_of_exactly_the_minimum_is_allowed(): void
    {
        [$subscription, , $newPlan] = $this->scenario(currentCents: 10000, newCents: 10050);

        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_EDGE'], 200),
            'api.razorpay.com/v1/subscriptions/*' => Http::response(['id' => 'sub_ABC123'], 200),
        ]);

        $this->assertTrue($this->gateway()->changePlan($subscription, $newPlan, 'month')['ok']);
    }

    #[Test]
    public function a_plan_with_no_price_for_the_cycle_is_refused(): void
    {
        [$subscription, , $newPlan] = $this->scenario();
        $newPlan->update(['yearly_price_cents' => null]);

        Http::fake();

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'year');

        $this->assertFalse($result['ok']);
        $this->assertSame('New plan has no price for this billing cycle.', $result['error']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_non_razorpay_subscription_is_refused(): void
    {
        [$subscription, , $newPlan] = $this->scenario();
        $subscription->update(['gateway' => 'stripe']);

        Http::fake();

        $this->assertFalse($this->gateway()->changePlan($subscription, $newPlan, 'month')['ok']);
        Http::assertNothingSent();
    }

    /** Plan creation failing must not leave the subscription half-changed. */
    #[Test]
    public function a_failed_plan_creation_aborts_before_the_patch(): void
    {
        [$subscription, $currentPlan, $newPlan] = $this->scenario();

        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['error' => ['description' => 'Invalid amount']], 400),
        ]);

        $result = $this->gateway()->changePlan($subscription, $newPlan, 'month');

        $this->assertFalse($result['ok']);
        $this->assertSame('Invalid amount', $result['error']);
        $this->assertSame($currentPlan->id, $subscription->refresh()->plan_id);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    /** The extraction in createCheckout() must not have changed its behaviour. */
    #[Test]
    public function create_checkout_still_creates_a_plan_then_a_subscription(): void
    {
        $plan = Plan::factory()->create(['monthly_price_cents' => 29900, 'currency_code' => 'INR']);
        $user = User::factory()->create(['role' => 'client']);

        Http::fake([
            'api.razorpay.com/v1/plans' => Http::response(['id' => 'plan_CHK'], 200),
            'api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_CHK', 'short_url' => 'https://rzp.io/i/abc'], 200),
        ]);

        $result = $this->gateway()->createCheckout($user, $plan, 'month');

        $this->assertSame('https://rzp.io/i/abc', $result['url'] ?? null);

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://api.razorpay.com/v1/plans'
            && $r['item']['amount'] === 29900
            && $r['period'] === 'monthly');

        // total_count IS correct on subscription CREATION — only the PATCH omits it.
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://api.razorpay.com/v1/subscriptions'
            && $r['plan_id'] === 'plan_CHK'
            && $r['total_count'] === 120);
    }
}
