<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\PayPalGateway;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ BUG-034 — A TRANSIENT FAILURE MUST NOT PERMANENTLY DEDUPE AN EVENT ═════
 *
 * `WebhookIdempotencyService::isNewEvent()` CLAIMS an event id before the
 * handler runs. If the handler then throws, the claim must be RELEASED so the
 * gateway's automatic retry can reprocess it. Otherwise the retry is discarded
 * as a duplicate and the event — usually a renewal — is lost.
 *
 * ⚠️ The loss is silent in every direction: the handler never completes, so
 * nothing is recorded; the retry is deduped, so nothing is logged; and no job
 * fails, so `failed_jobs` stays empty. The customer keeps their subscription
 * while the charge is never recorded.
 *
 * PayPal was the last gateway missing the release. It was previously expected to
 * close by deletion along with Paddle; PayPal is now a kept gateway, so it was
 * fixed instead.
 */
class WebhookIdempotencyReleaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ A REAL FAILURE, DRIVEN THROUGH THE REAL HANDLER.
     *
     * The gateway is built with an EMPTY webhook id, which skips signature
     * verification outside production — so `handleWebhook()` runs end to end.
     * The payload then carries a currency far longer than the column allows, so
     * the write throws exactly the way a transient DB fault would.
     */
    #[Test]
    public function paypal_releases_the_idempotency_lock_when_the_handler_throws(): void
    {
        $service = app(WebhookIdempotencyService::class);
        $eventId = 'WH-BUG034-TEST';

        $payload = json_encode([
            'id' => $eventId,
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'id' => 'PAY-TXN-1',
                'billing_agreement_id' => 'I-SUB-1',
                // varchar(10) — this overflows and throws inside the handler.
                'amount' => ['total' => '9.99', 'currency' => str_repeat('X', 64)],
            ],
        ]);

        $gateway = new PayPalGateway('cid', 'secret', true, 'http://s', 'http://c', '');
        $request = Request::create('/webhooks/paypal', 'POST', [], [], [], [], $payload);

        $response = $gateway->handleWebhook($request);

        $this->assertSame(500, $response->getStatusCode(),
            'The handler did not actually fail — this test proves nothing unless it does.');

        // The claim must be gone, so PayPal's retry is treated as new.
        $this->assertTrue($service->isNewEvent('paypal', $eventId),
            'BUG-034: the idempotency lock was NOT released after the handler threw, '
            .'so PayPal\'s retry of this event would be silently discarded as a duplicate.');
    }

    /** Positive control: the lock really is claimed on the way in. */
    #[Test]
    public function the_service_claims_an_event_on_first_sight(): void
    {
        $service = app(WebhookIdempotencyService::class);

        $this->assertTrue($service->isNewEvent('paypal', 'WH-CLAIM-1'));
        $this->assertFalse($service->isNewEvent('paypal', 'WH-CLAIM-1'),
            'Without this, the assertion in the test above could pass for the wrong reason.');
    }

    /**
     * ⚠️ THE GUARD, so this cannot regress for a gateway nobody is thinking about.
     *
     * Any gateway that claims an event id must also release it. A text scan is
     * the right tool: the defect is a MISSING call, and you cannot write a
     * behavioural test for a gateway you forgot exists.
     */
    #[Test]
    public function every_gateway_that_claims_an_event_also_releases_it(): void
    {
        $offenders = [];

        foreach (glob(app_path('Services/Billing/*Gateway.php')) as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, 'isNewEvent(')) {
                continue; // does not use the idempotency service at all
            }

            if (! str_contains($source, '->release(')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'These gateways claim a webhook event id but never release it on handler failure (BUG-034). '
            .'A transient error in any of them permanently dedupes the event: '.implode(', ', $offenders));
    }
}
