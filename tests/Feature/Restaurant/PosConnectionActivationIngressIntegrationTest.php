<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE CROSS-PHASE CONTRACT PROOF. Phase 1B's PetpoojaWebhookController
 * accepts a connection only when `status === PosConnection::STATUS_CONNECTED`
 * (`findByProviderAndRef()` + the controller's own explicit check — grepped
 * both directly, there is no `STATUS_ACTIVE` constant anywhere in this
 * codebase). Phase 1C's PosConnectionProvisioningService::activateSandbox()
 * persists that exact same constant. This test is the dynamic proof that the
 * two phases actually agree, end to end through the real HTTP route — not a
 * read of the source claiming they do. If a future change ever lets the two
 * drift (the same "one concept implemented twice" trap CLAUDE.md documents
 * elsewhere in this codebase), this test fails with a 403 rather than the
 * expected 200.
 */
class PosConnectionActivationIngressIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_connection_created_tokened_and_activated_through_the_service_accepts_a_real_petpooja_delivery(): void
    {
        // This test pins INGRESS acceptance: what the controller persists at
        // the moment it says 200. Since Phase 2 slice 2 the controller also
        // dispatches ProcessPosWebhookEventJob, which the suite's
        // QUEUE_CONNECTION=sync would run inline and move the event on from
        // `pending`. The payload below is Phase 1B's pre-documentation shape
        // (no properties.Order), which that job now — correctly — fails
        // permanently. Faking the queue keeps this assertion about ingress-time
        // state, exactly as PetpoojaWebhookIngressTest does class-wide;
        // end-to-end processing is PetpoojaOrderProcessingEndToEndTest's job.
        Queue::fake();

        $service = app(PosConnectionProvisioningService::class);
        $outlet = RestaurantOutlet::factory()->create();

        // 1. Create the sandbox connection through the Phase 1C service.
        $connection = $service->createSandboxConnection($outlet, 'REST-E2E-1', null);
        $this->assertSame(PosConnection::STATUS_PENDING, $connection->status);
        $this->assertNull($connection->webhook_secret_hash);

        // 2. Generate a token through the Phase 1C service.
        $plaintext = $service->generateToken($connection);
        $this->assertNotEmpty($plaintext);

        // 3. Activate sandbox ingress through the Phase 1C service.
        $activated = $service->activateSandbox($connection->fresh());
        $this->assertSame(PosConnection::STATUS_CONNECTED, $activated->status);

        // 4. Send a VALID Petpooja webhook using the returned plaintext token,
        // through the real Phase 1B route — not a direct model/controller call.
        $payload = [
            'event' => 'orderdetails',
            'orderID' => 'ORD-E2E-1',
            'orderInfo' => ['total' => '100'],
            'properties' => ['Restaurant' => ['restID' => 'REST-E2E-1']],
            'token' => $plaintext,
        ];
        $rawBody = json_encode($payload);

        $response = $this->call(
            'POST',
            '/webhooks/pos/petpooja',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody,
        );

        // 5. Prove acceptance: 200 {"status":"ok"} and exactly one pending event.
        $response->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $events = PosWebhookEvent::query()->where('connection_id', $connection->id)->get();
        $this->assertCount(1, $events, 'Exactly one event must be stored for this delivery.');

        $event = $events->first();
        $this->assertSame(PosWebhookEvent::STATUS_PENDING, $event->processing_status);
        $this->assertSame('orderdetails', $event->event_type);
        $this->assertSame($connection->workspace_id, $event->workspace_id);
        $this->assertSame(hash('sha256', $rawBody), $event->payload_hash);
        $this->assertNotNull($connection->fresh()->last_event_at);

        // And the valid, authenticated orderdetails event was handed to the
        // processing job exactly once.
        Queue::assertPushed(ProcessPosWebhookEventJob::class, fn (ProcessPosWebhookEventJob $job) => $job->eventId === $event->id);
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }
}
