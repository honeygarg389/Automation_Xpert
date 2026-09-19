<?php

namespace App\Modules\Restaurant\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Restaurant\Exceptions\UnprocessablePosWebhookEventException;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use App\Services\AuditLogService;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 2, slice 2. Consumes ONE `pending` `PosWebhookEvent` and turns it
 * into a `RestaurantBill` via {@see PetpoojaOrderIngestionService}.
 *
 * ─── Lifecycle: pending -> processing -> processed | failed ─────────────────
 *
 * Every transition is a single atomic conditional `UPDATE`, never a
 * read-modify-write:
 *
 *   1. CLAIM      pending    -> processing   (WHERE processing_status =
 *                 'pending'; 0 rows means someone else owns or finished it —
 *                 return quietly). Stamps `processing_started_at` and bumps
 *                 `attempts` in the same statement.
 *   2. SUCCESS    processing -> processed
 *   3. TRANSIENT  processing -> pending, then rethrow so Laravel's own
 *      failure      tries/backoff fires another attempt. On the last try:
 *                   processing -> failed (terminal), still rethrown so it
 *                   also lands in `failed_jobs`.
 *   4. PERMANENT  processing -> failed immediately, NO rethrow, NO retry.
 *      failure      A structurally invalid payload
 *                   ({@see UnprocessablePosWebhookEventException}) cannot
 *                   become valid by being retried, so it fails on its FIRST
 *                   execution with a specific reason. The event row and its
 *                   audit entry are the record; there is nothing for an
 *                   operator to replay from `failed_jobs`.
 *
 * ─── The lease, and why every write after the claim is FENCED ───────────────
 *
 * `processing_started_at` is a LEASE. If a worker is killed mid-job the row is
 * left `processing` forever unless something recovers it:
 * `restaurant:sweep-stalled-webhook-events` atomically flips a lease older than
 * {@see self::LEASE_MINUTES} back to `pending` and re-dispatches this job.
 *
 * That makes two runs for one event possible (a slow "dead" worker that was
 * merely stalled, plus its replacement). So each run remembers the exact
 * `$claimedAt` it stamped, and every later write is conditional on
 * `processing_status = 'processing' AND processing_started_at = $claimedAt`
 * (see {@see self::ownedRow()}). A run whose lease was reclaimed matches 0
 * rows and silently cannot flip the event to `failed` after the replacement
 * already finished, nor write a second audit entry. The BILL is safe
 * regardless: `restaurant_bills` is written by an idempotent upsert against
 * UNIQUE(connection_id, external_order_id).
 *
 * A poison event that kills its worker every time would otherwise be
 * reclaimed forever, so a claim beyond {@see self::MAX_CLAIMS} fails
 * permanently instead of processing.
 *
 * ─── Workspace context ──────────────────────────────────────────────────────
 *
 * `middleware()` establishes tenant context from the event's OWN
 * `workspace_id` before `handle()` runs any scoped lookup (RestaurantBill,
 * Contact and RestaurantOutlet are `BelongsToWorkspace`). See
 * `EstablishesWorkspaceContext`'s own docblock for the incident history.
 *
 * ─── Scope: bill ingestion ONLY ─────────────────────────────────────────────
 *
 * This job never sends a WhatsApp message, digital bill, feedback request,
 * outbound webhook, or any external HTTP request, and never dispatches
 * another job. See `PetpoojaOrderIngestionService`'s docblock.
 */
class ProcessPosWebhookEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * How long a `processing` lease may run before the sweep presumes the
     * worker dead. Deliberately far above `$timeout` (60s) and the queue's
     * `retry_after` (90s): a live run can never legitimately outlast it.
     */
    public const LEASE_MINUTES = 10;

    /**
     * Total claims (queue retries plus lease reclaims) an event may consume.
     * `$tries` is 3, so a healthy event never gets near this; it exists only
     * to stop a payload that repeatedly kills its worker being reclaimed
     * forever.
     */
    public const MAX_CLAIMS = 5;

    /** Same base schedule as ProcessEcommerceWebhookJob, for consistency across webhook-processing jobs. */
    private const BACKOFF_SECONDS = [30, 120, 300];

    public function __construct(public readonly int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(PosWebhookEvent::class, $this->eventId)];
    }

    public function handle(PetpoojaOrderIngestionService $ingestion, AuditLogService $audit): void
    {
        // Whole-second precision on purpose: the DB column stores no
        // fraction, and this exact value is the fencing token every later
        // write compares against.
        $claimedAt = now()->startOfSecond();

        $claimed = DB::table('pos_webhook_events')
            ->where('id', $this->eventId)
            ->where('processing_status', PosWebhookEvent::STATUS_PENDING)
            ->update([
                'processing_status' => PosWebhookEvent::STATUS_PROCESSING,
                'processing_started_at' => $claimedAt,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // Already claimed by another run, already processed/failed, or
            // quarantined and never should have been dispatched. Never an
            // error — this is the race-safety this design exists for.
            return;
        }

        $event = PosWebhookEvent::find($this->eventId);

        if ($event === null) {
            // Claimed a row that vanished between the UPDATE and this SELECT
            // (nothing deletes pos_webhook_events rows, but a job must never
            // assume it — only its own atomic claim).
            return;
        }

        if ($event->attempts > self::MAX_CLAIMS) {
            $this->failPermanently(
                $event,
                $claimedAt,
                $audit,
                'Gave up after '.($event->attempts - 1).' processing attempts that never completed (each lease expired).',
            );

            return;
        }

        try {
            $billId = $ingestion->ingest($event);

            $completed = $this->ownedRow($claimedAt)->update([
                'processing_status' => PosWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
                'failure_reason' => null,
                'updated_at' => now(),
            ]);

            if ($completed === 1) {
                $audit->logSystem(
                    'restaurant.bill.processed',
                    $event,
                    $event->workspace_id,
                    [
                        'restaurant_bill_id' => $billId,
                        'connection_id' => $event->connection_id,
                        'provider' => $event->provider,
                    ],
                );
            }
        } catch (UnprocessablePosWebhookEventException $e) {
            // Deterministic: the same bytes will fail the same way every time.
            $this->failPermanently($event, $claimedAt, $audit, $e->getMessage());
        } catch (\Throwable $e) {
            $isLastTry = $this->attempts() >= $this->tries;

            $update = [
                'processing_status' => $isLastTry ? PosWebhookEvent::STATUS_FAILED : PosWebhookEvent::STATUS_PENDING,
                'failure_reason' => Str::limit($e->getMessage(), 500, ''),
                'updated_at' => now(),
            ];

            if ($isLastTry) {
                $update['failed_at'] = now();
            }

            $owned = $this->ownedRow($claimedAt)->update($update);

            if ($isLastTry && $owned === 1) {
                $audit->logSystem(
                    'restaurant.bill.processing_failed',
                    $event,
                    $event->workspace_id,
                    [
                        'connection_id' => $event->connection_id,
                        'provider' => $event->provider,
                        'attempts' => $this->attempts(),
                        'error' => Str::limit($e->getMessage(), 255, ''),
                    ],
                );
            }

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        // No payload logged here, same convention as ProcessEcommerceWebhookJob —
        // the failure_reason on pos_webhook_events already carries the message.
        // A worker that died repeatedly never reaches handle()'s catch, so the
        // event is still `processing` here; the sweep's lease reclaim (bounded
        // by MAX_CLAIMS) is what recovers it.
        Log::error('restaurant.pos_webhook_event.process_failed', [
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * The event row, but ONLY while this run still holds its lease. Every
     * write after the claim goes through here — see the class docblock.
     */
    private function ownedRow(Carbon $claimedAt): Builder
    {
        return DB::table('pos_webhook_events')
            ->where('id', $this->eventId)
            ->where('processing_status', PosWebhookEvent::STATUS_PROCESSING)
            ->where('processing_started_at', $claimedAt);
    }

    /**
     * Terminal failure with no retry and no rethrow. Records the specific
     * reason on the event, and one audit entry (only if this run still owned
     * the lease — a superseded run must not double-report).
     */
    private function failPermanently(PosWebhookEvent $event, Carbon $claimedAt, AuditLogService $audit, string $reason): void
    {
        $updated = $this->ownedRow($claimedAt)->update([
            'processing_status' => PosWebhookEvent::STATUS_FAILED,
            'failure_reason' => Str::limit($reason, 500, ''),
            'failed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated === 1) {
            $audit->logSystem(
                'restaurant.bill.processing_failed',
                $event,
                $event->workspace_id,
                [
                    'connection_id' => $event->connection_id,
                    'provider' => $event->provider,
                    'attempts' => (int) $event->attempts,
                    'permanent' => true,
                    'error' => Str::limit($reason, 255, ''),
                ],
            );
        }
    }
}
