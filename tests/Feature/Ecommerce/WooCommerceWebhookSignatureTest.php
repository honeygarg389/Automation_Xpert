<?php

namespace Tests\Feature\Ecommerce;

use App\Modules\Ecommerce\Jobs\ProcessEcommerceWebhookJob;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The WooCommerce handler previously wrapped its HMAC check in `if ($signature)`,
 * so omitting the header skipped verification altogether. The signature is now
 * mandatory; these tests pin that behaviour.
 *
 * Note the route is additionally gated by verifyToken(), which requires
 * ?token=<webhook_secret>. Every case below supplies a valid token so that the
 * signature check itself is what is under test.
 */
class WooCommerceWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'woo-test-secret-value';

    private function store(): EcommerceStore
    {
        $workspace = Workspace::factory()->create();

        return EcommerceStore::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'platform' => 'woocommerce',
            'name' => 'Test Store',
            'domain' => 'store.example.com',
            'status' => 'active',
            'last_test_status' => 'unknown',
            'webhook_secret' => self::SECRET,
        ]);
    }

    /** The route binds by uuid, and verifyToken() requires the secret as ?token=. */
    private function url(EcommerceStore $store): string
    {
        return route('webhooks.ecommerce.woocommerce', ['store' => $store->uuid])
            .'?token='.self::SECRET;
    }

    private function sign(string $payload): string
    {
        return base64_encode(hash_hmac('sha256', $payload, self::SECRET, true));
    }

    public function test_request_without_signature_header_is_rejected(): void
    {
        Queue::fake();
        $store = $this->store();

        $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            ['HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['id' => 1])
        )->assertStatus(401);

        Queue::assertNotPushed(ProcessEcommerceWebhookJob::class);
    }

    public function test_request_with_empty_signature_header_is_rejected(): void
    {
        Queue::fake();
        $store = $this->store();

        $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => '',
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['id' => 1])
        )->assertStatus(401);

        Queue::assertNotPushed(ProcessEcommerceWebhookJob::class);
    }

    public function test_request_with_wrong_signature_is_rejected(): void
    {
        Queue::fake();
        $store = $this->store();

        $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode('not-the-right-signature'),
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['id' => 1])
        )->assertStatus(401);

        Queue::assertNotPushed(ProcessEcommerceWebhookJob::class);
    }

    public function test_signature_computed_over_a_different_body_is_rejected(): void
    {
        Queue::fake();
        $store = $this->store();

        $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => $this->sign(json_encode(['id' => 999])),
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['id' => 1])
        )->assertStatus(401);

        Queue::assertNotPushed(ProcessEcommerceWebhookJob::class);
    }

    public function test_request_with_valid_signature_is_accepted(): void
    {
        Queue::fake();
        $store = $this->store();
        $payload = json_encode(['id' => 1, 'status' => 'processing']);

        $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => $this->sign($payload),
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
                'HTTP_X_WC_WEBHOOK_ID' => 'evt-1',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        )->assertOk();

        Queue::assertPushed(ProcessEcommerceWebhookJob::class);
    }

    public function test_store_without_a_webhook_secret_is_rejected(): void
    {
        Queue::fake();
        $store = $this->store();
        $payload = json_encode(['id' => 1]);
        $signature = $this->sign($payload);

        // Clearing the secret must fail closed. verifyToken() rejects first with
        // 403 here, which is the correct outcome — the point is that a blank
        // secret is never used as an HMAC key.
        $store->update(['webhook_secret' => null]);

        $response = $this->call(
            'POST',
            $this->url($store),
            [],
            [],
            [],
            [
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );

        $this->assertContains($response->getStatusCode(), [401, 403]);
        Queue::assertNotPushed(ProcessEcommerceWebhookJob::class);
    }
}
