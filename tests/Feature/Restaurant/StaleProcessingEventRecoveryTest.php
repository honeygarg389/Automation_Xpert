<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2 — recovery from a worker that died mid-job, with NOTHING
 * faked: the sweep really reclaims the lease and (QUEUE_CONNECTION=sync)
 * really runs `ProcessPosWebhookEventJob` through middleware, claim, upsert
 * and audit.
 *
 * The property under test is the one that makes lease recovery safe to have:
 * reclaimed work is idempotent, because `restaurant_bills` is written by an
 * upsert against UNIQUE(connection_id, external_order_id). A worker can die
 * at either side of that write; both must converge on exactly one bill.
 */
class StaleProcessingEventRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: PosConnection, 1: PosWebhookEvent} */
    private function eventWithDeadWorker(int $leaseAgeMinutes): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);

        $payload = [
            'token' => 'irrelevant',
            'properties' => [
                'Restaurant' => ['restID' => $connection->external_ref],
                'Customer' => ['name' => 'Rohan', 'phone' => '8630026021'],
                'Order' => ['orderID' => 114, 'total' => 1158, 'core_total' => 1158, 'discount_total' => 0, 'tax_total' => 0,
                    'created_on' => '2025-04-04 11:45:35', 'status' => 'Success'],
                'Tax' => [], 'Discount' => [], 'OrderItem' => [],
            ],
            'event' => 'orderdetails',
        ];

        $event = PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PROCESSING,
            'processing_started_at' => now()->subMinutes($leaseAgeMinutes),
            'received_at' => now(),
            'payload_hash' => hash('sha256', 'dead-worker-event'),
            'raw_payload' => $payload,
            'attempts' => 1,
        ]);

        return [$connection, $event];
    }

    /** The worker died AFTER writing the bill but BEFORE marking the event processed. */
    #[Test]
    public function reclaiming_an_event_whose_bill_was_already_written_converges_on_the_same_single_bill(): void
    {
        [$connection, $event] = $this->eventWithDeadWorker(11);

        $originalBillId = WorkspaceContext::for($connection->workspace_id, fn () => app(PetpoojaOrderIngestionService::class)->ingest($event));
        $this->assertSame(1, DB::table('restaurant_bills')->count());

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertSame(2, $event->attempts, 'The reclaimed run is the second claim.');
        $this->assertNull($event->failure_reason, 'The "lease expired" note must clear on success.');
        $this->assertNotNull($event->processed_at);

        $this->assertSame(1, DB::table('restaurant_bills')->count(), 'Reclaimed work must never create a duplicate bill.');
        $this->assertSame($originalBillId, (int) DB::table('restaurant_bills')->value('id'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'restaurant.bill.processed')->count());
    }

    /** The worker died BEFORE writing anything. */
    #[Test]
    public function reclaiming_an_event_whose_worker_died_before_writing_creates_the_bill(): void
    {
        [, $event] = $this->eventWithDeadWorker(11);
        $this->assertSame(0, DB::table('restaurant_bills')->count());

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $this->assertSame(PosWebhookEvent::STATUS_PROCESSED, $event->fresh()->processing_status);
        $this->assertSame(1, DB::table('restaurant_bills')->count());
    }

    /** Positive control: the SAME setup with a live lease is left completely alone. */
    #[Test]
    public function an_event_with_a_live_lease_is_neither_reclaimed_nor_processed_by_the_sweep(): void
    {
        [, $event] = $this->eventWithDeadWorker(2);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSING, $event->processing_status);
        $this->assertSame(1, $event->attempts, 'A live worker\'s event must not be claimed a second time.');
        $this->assertSame(0, DB::table('restaurant_bills')->count());
    }

    /** A worker killed on EVERY attempt is not reclaimed forever. */
    #[Test]
    public function a_poison_event_is_eventually_given_up_on_instead_of_reclaimed_forever(): void
    {
        [, $event] = $this->eventWithDeadWorker(11);
        $event->update(['attempts' => ProcessPosWebhookEventJob::MAX_CLAIMS]);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertStringContainsString('Gave up', (string) $event->failure_reason);
        $this->assertSame(0, DB::table('restaurant_bills')->count());

        // And now it is terminal: a further sweep does nothing to it.
        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->fresh()->processing_status);
    }
}
