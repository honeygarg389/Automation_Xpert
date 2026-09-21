<?php

namespace Tests\Feature\Restaurant;

use App\Models\AuditLog;
use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryService;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 2, slice 2. The pending -> processing -> processed/failed lifecycle,
 * the atomic claim, and the workspace-context wiring — see
 * ProcessPosWebhookEventJob's own docblock for the full design.
 *
 * Attempts-count control: `InteractsWithQueue::attempts()` returns 1 when no
 * underlying job is bound, and `Illuminate\Queue\Jobs\SyncJob::attempts()`
 * (this app's test QUEUE_CONNECTION) is ALSO hardcoded to always return 1 —
 * so neither a direct handle() call nor a real sync dispatch can exercise
 * the "last try" branch. `FakeJob::$attempts` is public and exists exactly
 * for this: bind it via setJob() to control what $this->attempts() reports.
 */
class ProcessPosWebhookEventJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderdetailsPayload(string $restId, array $overrides = []): array
    {
        return array_replace_recursive([
            'token' => 'irrelevant',
            'properties' => [
                'Restaurant' => ['restID' => $restId],
                'Customer' => ['name' => 'Rohan', 'phone' => '8630026021'],
                'Order' => [
                    'orderID' => 114,
                    'total' => 1158,
                    'core_total' => 1158,
                    'discount_total' => 0,
                    'tax_total' => 0,
                    'created_on' => '2025-04-04 11:45:35',
                ],
                'Tax' => [],
                'Discount' => [],
                'OrderItem' => [],
            ],
            'event' => 'orderdetails',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|null  $payload  a COMPLETE payload (already
     *                                              built via orderdetailsPayload()).
     *                                              Pass null to use the default
     *                                              fixture as-is — this does NOT
     *                                              re-merge against the base
     *                                              fixture, so a caller who
     *                                              removed a key (e.g. orderID)
     *                                              gets exactly that mutation,
     *                                              not the base value restored.
     */
    private function pendingEvent(PosConnection $connection, ?array $payload = null): PosWebhookEvent
    {
        $payload ??= $this->orderdetailsPayload($connection->external_ref);

        return PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'event_type' => 'orderdetails',
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'payload_hash' => hash('sha256', json_encode($payload).uniqid('', true)),
            'raw_payload' => $payload,
            'attempts' => 0,
        ]);
    }

    #[Test]
    public function a_real_dispatch_establishes_workspace_context_ingests_the_bill_and_marks_processed(): void
    {
        // Real dispatch (this app's test QUEUE_CONNECTION is 'sync', so this
        // runs the job's full pipeline INCLUDING middleware()) — the only
        // test in this file that proves EstablishesWorkspaceContext is
        // actually wired, not just declared.
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection);

        ProcessPosWebhookEventJob::dispatch($event->id);

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->failure_reason);
        $this->assertSame(1, $event->attempts);

        // RestaurantBill is BelongsToWorkspace-scoped and the job's middleware
        // has already unwound its WorkspaceContext by the time dispatch()
        // returns here — reading it back needs the SAME wrapping a real
        // controller/console caller would use, not proof of anything wrong.
        $bill = WorkspaceContext::for(
            $workspace->id,
            fn () => RestaurantBill::query()->where('webhook_event_id', $event->id)->first(),
        );
        $this->assertNotNull($bill);
        $this->assertSame($workspace->id, $bill->workspace_id);
        $this->assertNotNull($bill->contact_id, 'Contact resolution requires workspace context to have been established.');

        $audit = AuditLog::query()->where('action', 'restaurant.bill.processed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertSame($bill->id, $audit->meta['restaurant_bill_id']);
    }

    #[Test]
    public function it_is_a_no_op_when_the_event_is_not_pending_at_claim_time(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);
        $event->update(['processing_status' => PosWebhookEvent::STATUS_PROCESSED, 'processed_at' => now()]);

        $job = new ProcessPosWebhookEventJob($event->id);
        $job->handle(app(PetpoojaOrderIngestionService::class), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        // Raw, unscoped count deliberately — RestaurantBill::query() with no
        // ambient WorkspaceContext fails closed and would read 0 regardless
        // of whether a row exists, making that assertion prove nothing.
        $this->assertSame(0, DB::table('restaurant_bills')->count(), 'An already-processed event must never be re-ingested.');
        $this->assertSame(0, $event->fresh()->attempts, 'A claim that never happened must never increment attempts.');
    }

    /**
     * A stand-in for the ingestion service whose `ingest()` runs $behaviour.
     * (An anonymous subclass rather than a mock object: the service has no
     * constructor dependencies, and this keeps the stub statically typed.)
     *
     * @param  \Closure(PosWebhookEvent): int  $behaviour
     */
    private function ingestionStub(\Closure $behaviour): PetpoojaOrderIngestionService
    {
        return new class($behaviour) extends PetpoojaOrderIngestionService
        {
            /** @param \Closure(PosWebhookEvent): int $behaviour */
            public function __construct(private \Closure $behaviour) {}

            public function ingest(PosWebhookEvent $event): int
            {
                return ($this->behaviour)($event);
            }
        };
    }

    /**
     * A TRANSIENT failure stand-in (a database outage): unlike a structurally
     * invalid payload, retrying this can succeed, so it must stay retryable.
     */
    private function ingestionThatThrows(\Throwable $e): PetpoojaOrderIngestionService
    {
        return $this->ingestionStub(function () use ($e): int {
            throw $e;
        });
    }

    #[Test]
    public function a_transient_failure_reverts_to_pending_increments_attempts_and_rethrows(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);

        $job = new ProcessPosWebhookEventJob($event->id);
        // No setJob() -> attempts() defaults to 1, which is < tries (3), so
        // this exercises the "more tries left" branch.

        try {
            $job->handle($this->ingestionThatThrows(new \RuntimeException('database unavailable')), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));
            $this->fail('Expected the transient failure to propagate so Laravel can retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('database unavailable', $e->getMessage());
        }

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PENDING, $event->processing_status, 'Must revert to pending so a queue retry can reclaim it.');
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->failed_at);
        $this->assertSame('database unavailable', $event->failure_reason);
        $this->assertSame(0, DB::table('restaurant_bills')->count());
    }

    #[Test]
    public function a_transient_failure_on_the_last_try_marks_the_event_failed_and_audits_it(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);

        $job = new ProcessPosWebhookEventJob($event->id);
        $fakeJob = new FakeJob;
        $fakeJob->attempts = 3; // job's $tries === 3 -> this IS the last attempt
        $job->setJob($fakeJob);

        try {
            $job->handle($this->ingestionThatThrows(new \RuntimeException('database unavailable')), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));
            $this->fail('Expected the failure to propagate even on the last try.');
        } catch (\RuntimeException) {
        }

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertNotNull($event->failed_at);
        $this->assertSame(1, $event->attempts, 'The claim query runs once per handle() call regardless of which attempt number it is.');

        $audit = AuditLog::query()->where('action', 'restaurant.bill.processing_failed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertSame(3, $audit->meta['attempts']);
        $this->assertArrayNotHasKey('permanent', $audit->meta, 'A last-try transient failure is not a permanent payload defect.');
    }

    // ══ Permanent failure: a structurally invalid payload never retries ═════

    /**
     * @return array<string, array{0: \Closure(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function structurallyInvalidPayloads(): array
    {
        return [
            'orderID missing' => [function (array $p) {
                unset($p['properties']['Order']['orderID']);

                return $p;
            }, 'properties.Order.orderID'],
            'orderID null' => [function (array $p) {
                $p['properties']['Order']['orderID'] = null;

                return $p;
            }, 'properties.Order.orderID'],
            'orderID blank' => [function (array $p) {
                $p['properties']['Order']['orderID'] = '   ';

                return $p;
            }, 'blank'],
            'orderID empty string' => [function (array $p) {
                $p['properties']['Order']['orderID'] = '';

                return $p;
            }, 'blank'],
            'orderID is an array' => [function (array $p) {
                $p['properties']['Order']['orderID'] = [114];

                return $p;
            }, 'string or integer'],
            'orderID is a bool' => [function (array $p) {
                $p['properties']['Order']['orderID'] = true;

                return $p;
            }, 'string or integer'],
            'orderID is a float' => [function (array $p) {
                $p['properties']['Order']['orderID'] = 114.5;

                return $p;
            }, 'string or integer'],
            'orderID longer than the column' => [function (array $p) {
                $p['properties']['Order']['orderID'] = str_repeat('9', 65);

                return $p;
            }, '64 characters'],
            'Order object missing' => [function (array $p) {
                unset($p['properties']['Order']);

                return $p;
            }, 'properties.Order'],
            'Order is not an object' => [function (array $p) {
                $p['properties']['Order'] = 'not-an-object';

                return $p;
            }, 'properties.Order'],
            'properties missing' => [function (array $p) {
                unset($p['properties']);

                return $p;
            }, 'properties.Order'],
        ];
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     */
    #[Test]
    #[DataProvider('structurallyInvalidPayloads')]
    public function a_structurally_invalid_payload_fails_permanently_on_the_first_execution(\Closure $mutate, string $reasonContains): void
    {
        Queue::fake();
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection, $mutate($this->orderdetailsPayload($connection->external_ref)));

        $job = new ProcessPosWebhookEventJob($event->id);
        // ONE execution, attempt #1 of 3. It must not throw (a throw is what
        // makes Laravel schedule attempts 2 and 3).
        $job->handle(app(PetpoojaOrderIngestionService::class), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->processing_status, 'A deterministic payload defect must be terminal immediately.');
        $this->assertSame(1, $event->attempts, 'It must not have been claimed a second time.');
        $this->assertNotNull($event->failed_at);
        $this->assertNull($event->processed_at);
        $this->assertStringContainsString($reasonContains, (string) $event->failure_reason);

        $this->assertSame(0, DB::table('restaurant_bills')->count(), 'No bill may exist for an invalid payload.');
        Queue::assertNothingPushed();

        $audit = AuditLog::query()->where('action', 'restaurant.bill.processing_failed')->first();
        $this->assertNotNull($audit);
        $this->assertSame($workspace->id, $audit->workspace_id);
        $this->assertTrue($audit->meta['permanent']);
    }

    /**
     * The regression this whole section exists for: a missing orderID used to
     * throw, revert the event to `pending`, and burn three attempts spread
     * over ~7 minutes of backoff before finally failing. Running the job
     * again after the first execution must now be a no-op on a terminal row.
     */
    #[Test]
    public function a_permanently_failed_event_is_never_reclaimed_by_a_later_execution(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $payload = $this->orderdetailsPayload($connection->external_ref);
        unset($payload['properties']['Order']['orderID']);
        $event = $this->pendingEvent($connection, $payload);

        (new ProcessPosWebhookEventJob($event->id))->handle(app(PetpoojaOrderIngestionService::class), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));
        (new ProcessPosWebhookEventJob($event->id))->handle(app(PetpoojaOrderIngestionService::class), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertSame(1, $event->attempts, 'A failed event must not be claimed again.');
        $this->assertSame(1, AuditLog::query()->where('action', 'restaurant.bill.processing_failed')->count());
    }

    // ══ The lease fence: a superseded run cannot clobber its successor ══════

    /**
     * Simulates the sweep having reclaimed this run's lease and a REPLACEMENT
     * run having claimed the event (new processing_started_at) while this,
     * merely-slow, run was still working.
     */
    #[Test]
    public function a_run_whose_lease_was_reclaimed_cannot_mark_the_event_processed_or_audit_it(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);

        $ingestion = $this->ingestionStub(function () use ($event): int {
            DB::table('pos_webhook_events')->where('id', $event->id)->update([
                'processing_started_at' => now()->addSeconds(30), // the replacement's lease
                'attempts' => 2,
            ]);

            return 42;
        });

        (new ProcessPosWebhookEventJob($event->id))->handle($ingestion, app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSING, $event->processing_status, 'The replacement still owns the event.');
        $this->assertNull($event->processed_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'restaurant.bill.processed')->count(), 'A superseded run must not double-report.');
    }

    #[Test]
    public function a_superseded_run_that_later_fails_cannot_flip_a_processed_event_to_failed(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);

        $ingestion = $this->ingestionStub(function () use ($event): int {
            // The event reached a terminal state while this run was stuck.
            // Deliberately leaves processing_started_at UNTOUCHED, so this
            // test isolates the STATUS half of the fence; the lease-timestamp
            // half has its own test above (a_run_whose_lease_was_reclaimed…).
            DB::table('pos_webhook_events')->where('id', $event->id)->update([
                'processing_status' => PosWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            // …and now THIS run finally errors out on its last try.
            throw new \RuntimeException('late failure');
        });

        $job = new ProcessPosWebhookEventJob($event->id);
        $fakeJob = new FakeJob;
        $fakeJob->attempts = 3;
        $job->setJob($fakeJob);

        try {
            $job->handle($ingestion, app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));
            $this->fail('The exception is still rethrown for Laravel.');
        } catch (\RuntimeException) {
        }

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_PROCESSED, $event->processing_status, 'A finished event must never be flipped to failed by a stale run.');
        $this->assertNull($event->failed_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'restaurant.bill.processing_failed')->count());
    }

    // ══ The claim cap: a poison event is not reclaimed forever ══════════════

    #[Test]
    public function an_event_that_has_exhausted_its_claims_fails_permanently_without_being_processed(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);
        $event->update(['attempts' => ProcessPosWebhookEventJob::MAX_CLAIMS]); // the next claim is #MAX+1

        $ingestCalls = 0;
        $ingestion = $this->ingestionStub(function () use (&$ingestCalls): int {
            $ingestCalls++;

            return 0;
        });

        (new ProcessPosWebhookEventJob($event->id))->handle($ingestion, app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        $this->assertSame(0, $ingestCalls, 'An exhausted event must not even be handed to the ingestion service.');

        $event->refresh();
        $this->assertSame(PosWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertStringContainsString('Gave up after '.ProcessPosWebhookEventJob::MAX_CLAIMS, (string) $event->failure_reason);
        $this->assertSame(0, DB::table('restaurant_bills')->count());
    }

    /** Positive control: the claim ONE BELOW the cap still processes normally. */
    #[Test]
    public function an_event_on_its_last_permitted_claim_is_still_processed(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id]);
        $event = $this->pendingEvent($connection);
        $event->update(['attempts' => ProcessPosWebhookEventJob::MAX_CLAIMS - 1]);

        (new ProcessPosWebhookEventJob($event->id))->handle(app(PetpoojaOrderIngestionService::class), app(AuditLogService::class), app(RestaurantDigitalBillDeliveryService::class));

        $this->assertSame(PosWebhookEvent::STATUS_PROCESSED, $event->fresh()->processing_status);
        $this->assertSame(1, DB::table('restaurant_bills')->count());
    }

    #[Test]
    public function the_atomic_claim_increments_attempts_and_sets_processing_started_at(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $connection = PosConnection::factory()->create([
            'workspace_id' => $workspace->id,
            'default_phone_country' => 'IN',
        ]);
        $event = $this->pendingEvent($connection);
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->processing_started_at);

        ProcessPosWebhookEventJob::dispatch($event->id);

        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processing_started_at);
    }
}
