<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2. `restaurant:sweep-stalled-webhook-events` is a SAFETY NET
 * only — see the command's own docblock. `Queue::fake()` throughout: this
 * tests which rows get redispatched, not job execution.
 */
class SweepStalledPosWebhookEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function connection(): PosConnection
    {
        return PosConnection::factory()->create();
    }

    #[Test]
    public function it_redispatches_a_pending_orderdetails_event_older_than_the_threshold(): void
    {
        $connection = $this->connection();
        $stale = PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'received_at' => now()->subMinutes(30),
        ]);

        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--minutes' => 10])->assertSuccessful();

        Queue::assertPushed(ProcessPosWebhookEventJob::class, fn (ProcessPosWebhookEventJob $job) => $job->eventId === $stale->id);
    }

    #[Test]
    public function it_never_redispatches_a_pending_event_still_within_the_threshold(): void
    {
        $connection = $this->connection();
        PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'received_at' => now()->subMinutes(2),
        ]);

        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--minutes' => 10])->assertSuccessful();

        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function it_never_redispatches_a_processing_processed_failed_or_quarantined_event(): void
    {
        $connection = $this->connection();
        foreach ([
            PosWebhookEvent::STATUS_PROCESSED,
            PosWebhookEvent::STATUS_FAILED,
            PosWebhookEvent::STATUS_QUARANTINED,
            'processing',
        ] as $status) {
            PosWebhookEvent::factory()->create([
                'connection_id' => $connection->id,
                'workspace_id' => $connection->workspace_id,
                'event_type' => $status === PosWebhookEvent::STATUS_QUARANTINED ? 'missing_event_key' : 'orderdetails',
                'processing_status' => $status,
                'received_at' => now()->subMinutes(30),
                // A `processing` row here holds a FRESH lease: a live worker.
                'processing_started_at' => $status === 'processing' ? now()->subMinutes(1) : null,
            ]);
        }

        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--minutes' => 10])->assertSuccessful();

        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function it_respects_the_limit_option(): void
    {
        $connection = $this->connection();
        PosWebhookEvent::factory()->count(3)->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'received_at' => now()->subMinutes(30),
        ]);

        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--minutes' => 10, '--limit' => 1])->assertSuccessful();

        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }

    // ══ Lease-based recovery of `processing` events ═════════════════════════

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function processingEvent(int $leaseAgeMinutes, array $overrides = []): PosWebhookEvent
    {
        $connection = $this->connection();

        return PosWebhookEvent::factory()->create(array_merge([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PROCESSING,
            'processing_started_at' => now()->subMinutes($leaseAgeMinutes),
            // Received recently, so the PENDING pass can never be what
            // dispatches it — any dispatch below is the lease reclaim's.
            'received_at' => now(),
            'attempts' => 1,
        ], $overrides));
    }

    #[Test]
    public function it_reclaims_and_redispatches_a_processing_event_whose_lease_is_older_than_ten_minutes(): void
    {
        $stale = $this->processingEvent(11);

        $this->artisan('restaurant:sweep-stalled-webhook-events')
            ->expectsOutputToContain('reclaimed 1 expired-lease')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PENDING, $stale->processing_status, 'The reclaim flips it back to pending so the job can claim it.');
        $this->assertStringContainsString('lease expired', (string) $stale->failure_reason);
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
        Queue::assertPushed(ProcessPosWebhookEventJob::class, fn (ProcessPosWebhookEventJob $job) => $job->eventId === $stale->id);
    }

    /** The other half: a live worker's event must never be duplicated. */
    #[Test]
    public function it_never_reclaims_a_processing_event_whose_lease_is_still_fresh(): void
    {
        $fresh = $this->processingEvent(2);
        $startedAt = $fresh->processing_started_at;

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $fresh->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSING, $fresh->processing_status);
        $this->assertEquals($startedAt, $fresh->processing_started_at, 'A live lease must not be touched.');
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function the_lease_boundary_separates_a_nine_minute_lease_from_an_eleven_minute_one(): void
    {
        $live = $this->processingEvent(9);
        $dead = $this->processingEvent(11);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        $this->assertSame(PosWebhookEvent::STATUS_PROCESSING, $live->fresh()->processing_status);
        $this->assertSame(PosWebhookEvent::STATUS_PENDING, $dead->fresh()->processing_status);
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
        Queue::assertPushed(ProcessPosWebhookEventJob::class, fn (ProcessPosWebhookEventJob $job) => $job->eventId === $dead->id);
    }

    #[Test]
    public function the_lease_minutes_option_overrides_the_ten_minute_default(): void
    {
        $event = $this->processingEvent(5);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);

        $this->artisan('restaurant:sweep-stalled-webhook-events', ['--lease-minutes' => 3])->assertSuccessful();
        Queue::assertPushed(ProcessPosWebhookEventJob::class, fn (ProcessPosWebhookEventJob $job) => $job->eventId === $event->id);
    }

    /** A row with no lease stamp is unknowable, not expired — leave it alone. */
    #[Test]
    public function it_never_reclaims_a_processing_event_that_has_no_lease_timestamp(): void
    {
        $this->processingEvent(60, ['processing_started_at' => null]);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    #[Test]
    public function it_never_reclaims_a_stale_processing_event_that_is_not_an_orderdetails_event(): void
    {
        $this->processingEvent(60, ['event_type' => 'missing_event_key']);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }

    /**
     * A reclaimed row becomes `pending`, and an event that has sat for hours
     * is ALSO old by received_at. Were the reclaim to run before the pending
     * pass collected its ids, the same run would dispatch it twice.
     */
    #[Test]
    public function a_reclaimed_event_is_dispatched_exactly_once_even_though_it_is_also_old_by_received_at(): void
    {
        $this->processingEvent(11, ['received_at' => now()->subHours(3)]);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }

    #[Test]
    public function a_second_sweep_right_after_the_first_does_not_dispatch_the_reclaimed_event_again(): void
    {
        $this->processingEvent(11);

        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();
        $this->artisan('restaurant:sweep-stalled-webhook-events')->assertSuccessful();

        // The second run sees it as `pending` but only seconds old by
        // received_at, so the (separate) pending pass rightly ignores it.
        Queue::assertPushed(ProcessPosWebhookEventJob::class, 1);
    }

    /**
     * ⚠️ ATOMICITY. The sweep SELECTs its candidates, then UPDATEs each one.
     * This makes a live worker refresh its lease in the gap between the two —
     * exactly the race a read-then-write reclaim would lose. Because the
     * UPDATE re-checks the cutoff itself, it must match nothing, and nothing
     * may be dispatched.
     */
    #[Test]
    public function a_lease_refreshed_between_the_sweeps_select_and_its_update_is_not_reclaimed(): void
    {
        $event = $this->processingEvent(11);

        $armed = true;
        DB::beforeExecuting(function (string $query) use (&$armed, $event) {
            if (! $armed || ! str_starts_with($query, 'update `pos_webhook_events` set `processing_status`')) {
                return;
            }
            $armed = false;
            DB::table('pos_webhook_events')->where('id', $event->id)->update(['processing_started_at' => now()]);
        });

        $this->artisan('restaurant:sweep-stalled-webhook-events')
            ->expectsOutputToContain('reclaimed 0 expired-lease')
            ->assertSuccessful();

        $this->assertFalse($armed, 'Test setup: the race hook never fired, so nothing was proved.');
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSING, $event->fresh()->processing_status, 'A lease a live worker just refreshed must not be reclaimed.');
        Queue::assertNotPushed(ProcessPosWebhookEventJob::class);
    }
}
