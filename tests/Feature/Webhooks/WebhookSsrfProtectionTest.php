<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\DispatchWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the three request-facing layers of the webhook SSRF fix:
 *   1. creation/update validation rejects non-public destinations
 *   2. the queued job re-checks at send time (DNS-rebinding guard)
 *   3. the receiver's response body is never returned to the customer
 *
 * Range classification itself is covered exhaustively and without a database in
 * Tests\Unit\Rules\PublicHttpUrlTest.
 */
class WebhookSsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        // Phase 0 slice 9: webhook_endpoints.workspace_id is NOT NULL and scoped,
        // so the endpoint must land in the acting user's workspace or route
        // binding will not resolve it. UserFactory creates no workspace.
        ['user' => $user] = $this->createWorkspaceContext([], [
            'role' => 'client',
            'email_verified_at' => now(),
        ]);

        return $user;
    }

    /** @return array<string, array{0: string}> */
    public static function blockedDestinations(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/hook'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'rfc1918 10/8' => ['https://10.0.0.1/hook'],
            'rfc1918 192.168/16' => ['https://192.168.1.1/hook'],
            'cgnat' => ['https://100.64.0.1/hook'],
            'this-network' => ['https://0.0.0.0/hook'],
            'loopback v6' => ['https://[::1]/hook'],
            'ipv4-mapped v6' => ['https://[::ffff:127.0.0.1]/hook'],
            'plain http' => ['http://example.com/hook'],
            'non-standard port' => ['https://example.com:8080/hook'],
        ];
    }

    #[DataProvider('blockedDestinations')]
    public function test_endpoint_creation_rejects_non_public_urls(string $url): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.webhooks.store'), [
                'url' => $url,
                'events' => ['contact.created'],
            ])
            ->assertSessionHasErrors('url');

        $this->assertDatabaseCount('webhook_endpoints', 0);
    }

    /**
     * The API route is guarded by `api.ability:webhooks:write`, which reads
     * `currentAccessToken()`. actingAs() does not populate that, so it 401s
     * before validation runs — issue a real token, mirroring
     * Tests\Feature\Api\V1\OutboundWebhookApiTest.
     */
    #[DataProvider('blockedDestinations')]
    public function test_api_endpoint_creation_rejects_non_public_urls(string $url): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('ssrf-test', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/webhooks', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    #[DataProvider('blockedDestinations')]
    public function test_endpoint_update_rejects_non_public_urls(string $url): void
    {
        $user = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create([
            'user_id' => $user->id,
            'url' => 'https://example.com/original',
        ]);

        $this->actingAs($user)
            ->put(route('client.webhooks.update', $endpoint), [
                'url' => $url,
                'events' => ['contact.created'],
            ])
            ->assertSessionHasErrors('url');

        $this->assertSame('https://example.com/original', $endpoint->fresh()->url);
    }

    public function test_public_https_url_is_accepted(): void
    {
        if (PublicHttpUrl::resolve('example.com') === []) {
            $this->markTestSkipped('DNS unavailable in this environment.');
        }

        $user = $this->clientUser();

        $this->actingAs($user)
            ->post(route('client.webhooks.store'), [
                'url' => 'https://example.com/hook',
                'events' => ['contact.created'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('webhook_endpoints', ['url' => 'https://example.com/hook']);
    }

    /**
     * The DNS-rebinding guard. The endpoint row is written directly, simulating
     * a destination that passed validation and was later re-pointed at an
     * internal address — the job must refuse to send it.
     */
    public function test_job_refuses_to_send_to_a_non_public_destination(): void
    {
        Http::fake();

        $user = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create([
            'user_id' => $user->id,
            'url' => 'https://169.254.169.254/latest/meta-data/',
        ]);

        try {
            (new DispatchWebhookJob($endpoint, 'contact.created', ['id' => 1]))->handle();
        } catch (\Throwable) {
            // fail() marks the job failed; the assertion below is the contract.
        }

        Http::assertNothingSent();

        $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->first();
        $this->assertNotNull($delivery, 'A delivery row should record the blocked attempt.');
        $this->assertNull($delivery->delivered_at);
    }

    /** The response body must never reach the customer. */
    public function test_delivery_response_body_is_not_exposed_to_the_customer(): void
    {
        $user = $this->clientUser();
        $endpoint = WebhookEndpoint::factory()->create([
            'user_id' => $user->id,
            'url' => 'https://example.com/hook',
        ]);

        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'contact.created',
            'payload' => ['id' => 1],
            'response_status' => 200,
            'response_body' => 'SECRET-INTERNAL-RESPONSE-BODY',
            'attempts' => 1,
            'delivered_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.webhooks.deliveries', $endpoint))
            ->assertOk();

        // Assert against the rendered payload, not just the model: $hidden alone
        // would not catch a controller that projected the column explicitly.
        $response->assertDontSee('SECRET-INTERNAL-RESPONSE-BODY', false);

        $props = $response->viewData('page')['props'];
        $encoded = json_encode($props);
        $this->assertStringNotContainsString('SECRET-INTERNAL-RESPONSE-BODY', $encoded);
        $this->assertStringNotContainsString('response_body', $encoded);

        // Customers should still get useful delivery metadata.
        $this->assertStringContainsString('response_status', $encoded);
    }

    public function test_model_hides_response_body_when_serialised(): void
    {
        $endpoint = WebhookEndpoint::factory()->create(['user_id' => $this->clientUser()->id]);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'contact.created',
            'payload' => ['id' => 1],
            'response_status' => 200,
            'response_body' => 'SECRET-INTERNAL-RESPONSE-BODY',
            'attempts' => 1,
        ]);

        $this->assertArrayNotHasKey('response_body', $delivery->toArray());
        // Still readable internally for debugging.
        $this->assertSame('SECRET-INTERNAL-RESPONSE-BODY', $delivery->response_body);
    }
}
