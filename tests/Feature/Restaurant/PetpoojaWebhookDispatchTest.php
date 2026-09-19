<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2. `PetpoojaWebhookController` dispatches
 * `ProcessPosWebhookEventJob` for exactly one outcome — a valid,
 * authenticated 'orderdetails' event — and never for anything else
 * (quarantined, rejected, or an exact-retry duplicate). `Queue::fake()`
 * throughout: this file tests DISPATCH DECISIONS, not job execution.
 */
class PetpoojaWebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/webhooks/pos/petpooja';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activeConnection(array $overrides = []): PosConnection
    {
        $connection = PosConnection::factory()->create(array_merge([
            'provider' => PosConnection::PROVIDER_PETPOOJA,
            'external_ref' => 'REST-'.fake()->unique()->numberBetween(1000, 999999),
            'status' => PosConnection::STATUS_CONNECTED,
        ], $overrides));

        $connection->forceFill(['webhook_secret_hash' => hash('sha256', $this->tokenFor($connection))])->save();

        return $connection;
    }

    private function tokenFor(PosConnection $connection): string
    {
        return 'token-for-'.$connection->external_ref;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderdetailsPayload(PosConnection $connection, array $overrides = []): array
    {
        return array_replace_recursive([
            'token' => $this->tokenFor($connection),
            'properties' => [
                'Restaurant' => ['restID' => $connection->external_ref],
                'Customer' => ['name' => 'Rohan', 'phone' => '8630026021'],
                'Order' => ['orderID' => 114, 'total' => 1158, 'core_total' => 1158, 'created_on' => '2025-04-04 11:45:35'],
                'Tax' => [],
                'Discount' => [],
                'OrderItem' => [],
            ],
            'event' => 'orderdetails',
        ], $overrides);
    }

    /**
     * @return TestResponse<Response>
     */
    private function postRaw(string $rawBody)
    {
        return $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $rawBody);
    }

    #[Test]
    public function a_valid_orderdetails_event_dispatches_the_processing_job_onto_the_restaurant_queue(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->orderdetailsPayload($connection));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $event = PosWebhookEvent::query()->first();

        Queue::assertPushed(ProcessPosWebhookEventJob::class, function (ProcessPosWebhookEventJob $job) use ($event) {
            return $job->eventId === $event->id && $job->queue === 'restaurant';
        });
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }

    #[Test]
    public function a_missing_event_key_is_quarantined_and_never_dispatches_a_job(): void
    {
        $connection = $this->activeConnection();
        $payload = $this->orderdetailsPayload($connection);
        unset($payload['event']);
        $rawBody = json_encode($payload);

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(PosWebhookEvent::STATUS_QUARANTINED, PosWebhookEvent::query()->first()->processing_status);
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function an_unsupported_event_value_is_quarantined_and_never_dispatches_a_job(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->orderdetailsPayload($connection, ['event' => 'something_else']));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(PosWebhookEvent::STATUS_QUARANTINED, PosWebhookEvent::query()->first()->processing_status);
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function a_rejected_request_never_dispatches_a_job(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->orderdetailsPayload($connection, ['token' => 'totally-wrong-token']));

        $this->postRaw($rawBody)->assertStatus(403);

        $this->assertSame(0, PosWebhookEvent::query()->count());
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function an_exact_retry_duplicate_only_dispatches_once_for_the_original_delivery(): void
    {
        $connection = $this->activeConnection();
        $rawBody = json_encode($this->orderdetailsPayload($connection));

        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);
        $this->postRaw($rawBody)->assertStatus(200)->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, PosWebhookEvent::query()->count());
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }
}
