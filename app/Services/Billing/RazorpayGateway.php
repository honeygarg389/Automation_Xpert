<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Events\PlanChanged;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\WebhookIdempotencyService;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Razorpay recurring billing via the Subscriptions API.
 *
 * Flow: create a Plan → create a Subscription (returns a hosted `short_url` the customer
 * is redirected to for mandate authorization) → Razorpay charges each cycle and notifies
 * us by webhook. All amounts are in the smallest currency unit (paise for INR), which
 * matches the app's `amount_cents` convention 1:1.
 *
 * Two distinct secrets are involved and must not be confused:
 *   - key_secret      → HTTP Basic auth + the redirect-return signature.
 *   - webhook secret  → the X-Razorpay-Signature HMAC on inbound webhooks.
 */
class RazorpayGateway implements BillingGatewayInterface
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    /**
     * Minimum difference Razorpay accepts between the old and new plan, in
     * currency subunits (paise). Documented as 50 = ₹0.50.
     */
    private const MIN_CHANGE_DIFFERENCE_SUBUNITS = 50;

    public function __construct(
        private string $keyId,
        private string $keySecret,
        private string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'Razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->keyId, $this->keySecret)
            ->acceptJson()
            ->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Razorpay is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        // Razorpay requires a finite cycle count; use a long horizon to emulate
        // open-ended. ⚠️ Counted in CYCLES, not months — the old `? 10 : 120`
        // meant 120 months, which applied to quarterly would have been 30 years.
        $totalCount = BillingCycle::horizonCycles($billingCycle);
        if ($totalCount === null) {
            return ['error' => "Unsupported billing cycle '{$billingCycle}'."];
        }

        // 1) Create a plan (item.amount in paise).
        $planResult = $this->createRazorpayPlan($plan, $billingCycle, $priceCents);
        if (isset($planResult['error'])) {
            return ['error' => $planResult['error']];
        }
        $razorpayPlanId = $planResult['id'];

        // 2) Create a subscription → short_url is the hosted authorization page.
        $body = [
            'plan_id' => $razorpayPlanId,
            'total_count' => $totalCount,
            'quantity' => 1,
            'customer_notify' => 1,
            'notes' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ];

        // A free trial delays the first charge.
        if (($plan->trial_days ?? 0) > 0) {
            $body['start_at'] = now()->addDays((int) $plan->trial_days)->getTimestamp();
        }

        $subRes = $this->http()->post(self::BASE_URL.'/subscriptions', $body);

        if (! $subRes->successful()) {
            Log::error('Razorpay create subscription failed', ['body' => $subRes->json(), 'user_id' => $user->id]);

            return ['error' => $subRes->json('error.description', 'Razorpay subscription creation failed.')];
        }

        $url = $subRes->json('short_url');
        if (! $url) {
            return ['error' => 'No checkout URL in Razorpay response.'];
        }

        return ['url' => $url, 'subscription_id' => $subRes->json('id')];
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();

        // Signature verification is mandatory whenever a webhook secret is configured.
        if ($this->webhookSecret) {
            $sig = $request->header('X-Razorpay-Signature', '');
            $expected = hash_hmac('sha256', $payload, $this->webhookSecret);
            if ($sig === '' || ! hash_equals($expected, $sig)) {
                Log::warning('Razorpay webhook signature verification failed');

                return new Response('Invalid signature', 401);
            }
        } elseif (app()->environment('production')) {
            Log::warning('Razorpay webhook secret not configured in production');

            return new Response('Webhook secret not configured', 401);
        }

        $data = json_decode($payload, true) ?: [];
        $event = $data['event'] ?? '';
        $eventId = $request->header('X-Razorpay-Event-Id') ?: ($data['id'] ?? null);

        if ($eventId && ! app(WebhookIdempotencyService::class)->isNewEvent('razorpay', $eventId)) {
            return new Response('OK', 200);
        }

        try {
            match ($event) {
                'subscription.activated', 'subscription.authenticated', 'subscription.resumed' => $this->handleSubscriptionActivated($data),
                'subscription.charged' => $this->handleSubscriptionCharged($data),
                'subscription.cancelled', 'subscription.completed', 'subscription.expired' => $this->handleSubscriptionEnded($data, 'canceled'),
                'subscription.halted', 'subscription.pending', 'subscription.paused' => $this->handleSubscriptionEnded($data, 'past_due'),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Razorpay webhook handler failed', ['event' => $event, 'error' => $e->getMessage()]);
            // Release the idempotency lock so Razorpay's automatic retry can reprocess.
            if ($eventId) {
                app(WebhookIdempotencyService::class)->release('razorpay', $eventId);
            }

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    private function handleSubscriptionActivated(array $data): void
    {
        $entity = $data['payload']['subscription']['entity'] ?? [];
        $subId = $entity['id'] ?? '';
        $notes = $entity['notes'] ?? [];
        $userId = (int) ($notes['user_id'] ?? 0);
        $planId = (int) ($notes['plan_id'] ?? 0);
        $billingCycle = $notes['billing_cycle'] ?? 'month';
        if (! $subId || ! $userId || ! $planId) {
            return;
        }

        $isNew = ! Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'razorpay', 'gateway_subscription_id' => $subId],
            [
                'user_id' => $userId,
                'plan_id' => $planId,
                'billing_cycle' => $billingCycle,
                'status' => $this->mapStatus($entity['status'] ?? 'active'),
                'starts_at' => now(),
                'ends_at' => null,
                'renews_at' => isset($entity['current_end']) ? Carbon::createFromTimestamp($entity['current_end']) : null,
            ]
        );

        if ($isNew) {
            $user = User::find($userId);
            $plan = Plan::find($planId);
            if ($user && $plan) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }
    }

    private function handleSubscriptionCharged(array $data): void
    {
        $subEntity = $data['payload']['subscription']['entity'] ?? [];
        $payEntity = $data['payload']['payment']['entity'] ?? [];
        $subId = $subEntity['id'] ?? '';
        $paymentId = $payEntity['id'] ?? null;
        if (! $subId || ! $paymentId) {
            return;
        }

        // The charge event can arrive before/without an activation event — ensure the
        // local subscription exists (idempotent upsert) before recording the payment.
        $this->handleSubscriptionActivated($data);

        $subscription = Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->with('user', 'plan')
            ->first();

        if (PaymentTransaction::where('gateway', 'razorpay')->where('gateway_transaction_id', $paymentId)->exists()) {
            return;
        }

        // A prior paid transaction means this is a recurring renewal, not the first charge.
        $isRenewal = $subscription
            && PaymentTransaction::where('gateway', 'razorpay')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->exists();

        $amountCents = (int) ($payEntity['amount'] ?? 0);
        $currency = strtoupper($payEntity['currency'] ?? 'INR');

        $transaction = PaymentTransaction::create([
            'user_id' => $subscription?->user_id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'razorpay',
            'gateway_transaction_id' => $paymentId,
            'amount_cents' => $amountCents,
            'currency_code' => $currency,
            'status' => 'paid',
            'payload' => $payEntity,
        ]);

        $this->generateInvoicePdf($transaction);

        if ($subscription && isset($subEntity['current_end'])) {
            $subscription->update(['renews_at' => Carbon::createFromTimestamp($subEntity['current_end'])]);
        }

        if ($isRenewal && $subscription && $subscription->user && $subscription->plan) {
            $subscription->refresh()->loadMissing('user', 'plan');
            SubscriptionRenewed::dispatch($subscription->user, $subscription, $subscription->plan, $amountCents, $currency);
        }
    }

    private function handleSubscriptionEnded(array $data, string $status): void
    {
        $subId = $data['payload']['subscription']['entity']['id'] ?? '';
        if (! $subId) {
            return;
        }
        $update = ['status' => $status];
        if ($status === 'canceled') {
            $update['ends_at'] = now();
        }
        Subscription::where('gateway', 'razorpay')
            ->where('gateway_subscription_id', $subId)
            ->update($update);
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'razorpay' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->post(self::BASE_URL.'/subscriptions/'.$subscription->gateway_subscription_id.'/cancel', [
            'cancel_at_cycle_end' => 0,
        ]);

        if ($res->successful()) {
            $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

            return true;
        }

        Log::error('Razorpay cancel failed', ['subscription_id' => $subscription->id, 'body' => $res->body()]);

        return false;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'razorpay' || ! $this->isConfigured()) {
            return false;
        }

        $res = $this->http()->get(self::BASE_URL.'/subscriptions/'.$subscription->gateway_subscription_id);
        if (! $res->successful()) {
            return false;
        }

        $currentEnd = $res->json('current_end');
        $subscription->update([
            'status' => $this->mapStatus($res->json('status', $subscription->status)),
            'renews_at' => $currentEnd ? Carbon::createFromTimestamp($currentEnd) : $subscription->renews_at,
        ]);

        return true;
    }

    /** Map Razorpay subscription statuses to the app's canonical status vocabulary. */
    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active', 'authenticated' => 'active',
            'created', 'pending' => 'incomplete',
            'halted', 'paused' => 'past_due',
            'cancelled', 'completed', 'expired' => 'canceled',
            default => strtolower($status),
        };
    }

    /**
     * Create a Razorpay-side plan object for one (plan, cycle) pair.
     *
     * Shared by createCheckout() and changePlan(). Razorpay has no notion of
     * updating a plan's price, so BOTH paths create a fresh plan object and point
     * the subscription at it — which is why this is extracted rather than
     * duplicated.
     *
     * ⚠️ Razorpay plan objects accumulate: one per checkout attempt and one per
     * plan change, including abandoned ones. That is inherent to the API, not a
     * leak — see the note in docs/billing-gateway-cleanup.md.
     *
     * @return array{id: string}|array{error: string}
     */
    private function createRazorpayPlan(Plan $plan, string $billingCycle, int $priceCents): array
    {
        // ⚠️ Razorpay is the only one of the four with a NATIVE `quarterly`
        // period; half_year has no native form and is monthly x 6.
        $mapping = BillingCycle::razorpay($billingCycle);
        if ($mapping === null) {
            return ['error' => "Unsupported billing cycle '{$billingCycle}'."];
        }

        $res = $this->http()->post(self::BASE_URL.'/plans', [
            'period' => $mapping['period'],
            'interval' => $mapping['interval'],
            'item' => [
                'name' => $plan->name,
                'amount' => $priceCents,
                'currency' => strtoupper($plan->currency_code ?? 'INR'),
            ],
            'notes' => [
                'plan_id' => (string) $plan->id,
                'billing_cycle' => $billingCycle,
            ],
        ]);

        if (! $res->successful()) {
            Log::error('Razorpay create plan failed', ['body' => $res->json(), 'plan_id' => $plan->id]);

            return ['error' => $res->json('error.description', 'Razorpay plan creation failed.')];
        }

        $id = $res->json('id');
        if (! $id) {
            return ['error' => 'No plan ID in Razorpay response.'];
        }

        return ['id' => (string) $id];
    }

    /**
     * In-place plan change, charged immediately with proration.
     *
     * ─── ⚠️ THIS FAILS BY DESIGN FOR UPI AND eMANDATE SUBSCRIPTIONS ────────────
     *
     * Razorpay's Update Subscription API documents two hard exclusions:
     *
     *   > "Subscriptions cannot be updated when payment mode is UPI"
     *   > "Emandate subscriptions cannot be updated" — they are
     *   >  "immutable post-authentication"
     *
     * (https://razorpay.com/docs/api/payments/subscriptions/update-subscription/)
     *
     * Only CARD-authorized subscriptions can be updated. A UPI or eNACH customer
     * attempting an upgrade will receive Razorpay's own rejection through the
     * error path below. **That is correct behaviour, not a bug** — the mandate a
     * customer authorized is what caps how much can be collected, and changing it
     * requires a new authorization. Do not "fix" this by suppressing the message;
     * the customer needs to know to cancel and re-subscribe.
     *
     * ⚠️ The gateway cannot tell in advance which it is: the app never records the
     * authorization method, and Razorpay does not return it on the subscription
     * object we hold. So the check cannot be moved earlier than the API call.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        if ($subscription->gateway !== 'razorpay' || ! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Razorpay is not configured.'];
        }

        $newPriceCents = $newPlan->priceCentsForCycle($billingCycle);
        if ($newPriceCents === null || $newPriceCents <= 0) {
            return ['ok' => false, 'error' => 'New plan has no price for this billing cycle.'];
        }

        $currentPlan = $subscription->plan ?? Plan::find($subscription->plan_id);
        $currentPriceCents = $currentPlan?->priceCentsForCycle($subscription->billing_cycle ?? $billingCycle);

        // ⚠️ RAZORPAY REFUSES A DIFFERENCE BELOW 50 SUBUNITS (₹0.50):
        //
        //   > "ensure that the prorated amount difference between the existing and
        //   >  new plans is at least 50 currency subunits, that is, ₹0.5."
        //   > "This is valid only when you update a Subscription immediately."
        //
        // (https://razorpay.com/docs/payments/subscriptions/update/)
        //
        // ⚠️ THE THRESHOLD IS ON THE **PRORATED** DIFFERENCE, WHICH WE CANNOT
        // COMPUTE — it depends on how much of the current cycle remains, which
        // only Razorpay knows. This check compares FULL PLAN PRICES instead, and
        // that is deliberately a one-way filter:
        //
        //   prorated difference <= full price difference, always (the proration
        //   factor is at most 1). So a full difference below 50 guarantees the
        //   prorated one is too, and refusing here can never reject a change
        //   Razorpay would have accepted.
        //
        // The converse does NOT hold: a full difference of 50+ can still prorate
        // to under 50 late in a cycle. Razorpay rejects those, and the error path
        // below surfaces it. This guard exists to turn the COMMON case — two plans
        // priced within half a rupee — into a sentence the customer can act on,
        // not to replace Razorpay's own check.
        if ($currentPriceCents !== null && abs($newPriceCents - $currentPriceCents) < self::MIN_CHANGE_DIFFERENCE_SUBUNITS) {
            return ['ok' => false, 'error' => 'Plan prices are too close to process this change — the minimum difference Razorpay requires is ₹0.50.'];
        }

        $planResult = $this->createRazorpayPlan($newPlan, $billingCycle, $newPriceCents);
        if (isset($planResult['error'])) {
            return ['ok' => false, 'error' => $planResult['error']];
        }

        // ⚠️ `total_count` is NOT sent, and its absence is deliberate. The Update
        // Subscription API does not accept it at all — the equivalent field is
        // `remaining_count`, which is optional and independent of `plan_id`. Every
        // parameter is optional; the only requirement is that at least one
        // updatable field is present. Sending `total_count` would be ignored at
        // best. Leaving `remaining_count` alone preserves the existing schedule,
        // which is what an in-place plan change should do.
        $res = $this->http()->patch(self::BASE_URL.'/subscriptions/'.$subscription->gateway_subscription_id, [
            'plan_id' => $planResult['id'],
            'schedule_change_at' => 'now',
        ]);

        if (! $res->successful()) {
            Log::error('Razorpay changePlan failed', [
                'subscription_id' => $subscription->id,
                'body' => $res->json(),
            ]);

            // This is where a UPI/eMandate subscription lands — see the class-level
            // note above. Razorpay's own description is the most useful thing we
            // can show, so it is passed through rather than replaced.
            return ['ok' => false, 'error' => $res->json('error.description', 'Could not update the subscription.')];
        }

        $meta = $subscription->gateway_metadata ?? [];
        $meta['razorpay_plan_id'] = $planResult['id'];

        $subscription->update([
            'plan_id' => $newPlan->id,
            'billing_cycle' => $billingCycle,
            'gateway_metadata' => $meta,
        ]);

        // ⚠️ Entitlements reconcile off PlanChanged. Stripe dispatches it
        // (StripeGateway:567-570); Square does not, which is an oversight there
        // rather than a pattern — without this the customer keeps the old plan's
        // limits after paying for the new one.
        $user = $subscription->user ?? User::find($subscription->user_id);
        if ($user && $currentPlan) {
            PlanChanged::dispatch($user, $subscription, $currentPlan, $newPlan);
        }

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Razorpay is not configured.'];
        }

        $paymentId = $transaction->gateway_transaction_id;
        if (! $paymentId) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $body = [];
        if ($amountCents) {
            $body['amount'] = $amountCents; // paise
        }

        $res = $this->http()->post(self::BASE_URL."/payments/{$paymentId}/refund", $body);

        if (! $res->successful()) {
            Log::error('Razorpay refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => $res->json('error.description') ?? 'Razorpay refund failed.'];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $amountCents ?? $transaction->amount_cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    public function fulfillCheckoutSession(string $sessionId): array
    {
        return ['ok' => false, 'error' => 'Razorpay fulfillment is handled via webhook.'];
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Razorpay: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
