<?php

namespace App\Modules\Restaurant\Console\Commands;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Safety net, NOT the primary processing path — the primary path is
 * `PetpoojaWebhookController` dispatching `ProcessPosWebhookEventJob`
 * immediately after persisting a `pending` `orderdetails` event.
 *
 * This exists only to catch what that primary path missed, in two ways:
 *
 *  1. STALLED PENDING — a row that stayed `pending` because the job was never
 *     dispatched (a crash between commit and dispatch) or a queue that
 *     silently stopped consuming (the `reserved=NEVER` failure mode this
 *     codebase has hit before). Re-dispatched as-is.
 *
 *  2. EXPIRED LEASE — a row left `processing` because the worker holding it
 *     was killed. Its `processing_started_at` is a lease; once older than
 *     `--lease-minutes` (default {@see ProcessPosWebhookEventJob::LEASE_MINUTES})
 *     the holder is presumed dead. The row is atomically flipped back to
 *     `pending` and re-dispatched.
 *
 * ⚠️ THE RECLAIM IS ONE CONDITIONAL UPDATE, and the dispatch happens only when
 * that update changed exactly one row:
 *
 *     UPDATE ... SET processing_status = 'pending'
 *      WHERE id = ? AND processing_status = 'processing'
 *        AND processing_started_at <= <cutoff>
 *
 * Two overlapping sweeps both try it; the database lets exactly one win, so an
 * event is never dispatched twice by the sweep. A FRESH lease (or a lease a
 * live worker refreshed between this command's SELECT and its UPDATE) matches
 * nothing and is left strictly alone — a currently-processing event is never
 * duplicated. And even if a "dead" worker turns out to be merely slow, the
 * job fences its own writes and the bill is an idempotent upsert, so no
 * duplicate bill or corrupted status is possible.
 *
 * ⚠️ Order matters: the pending pass runs FIRST and its ids are collected
 * BEFORE any reclaim, otherwise a row just reclaimed to `pending` (usually
 * old by `received_at`) would be picked up by the pending pass in the same run
 * and dispatched twice.
 *
 * ⚠️ `--minutes` defaults to 10, deliberately longer than
 * `ProcessPosWebhookEventJob::BACKOFF_SECONDS`'s longest jittered wait
 * (300s * 1.3 ≈ 390s ≈ 6.5 min). A shorter threshold would re-dispatch a row
 * that is `pending` only because it is waiting out its OWN normal retry
 * backoff, not because anything is actually stalled. A redundant dispatch is
 * harmless either way — the job's atomic claim means at most one of any two
 * concurrent dispatches for the same event does real work — but the default
 * is chosen to make that the rare case, not the common one.
 *
 * Only ever touches `orderdetails` events — quarantined events have no
 * processing job and must never be swept into one.
 */
class SweepStalledPosWebhookEventsCommand extends Command
{
    protected $signature = 'restaurant:sweep-stalled-webhook-events
        {--minutes=10 : Age threshold (by received_at) for a pending event to count as stalled}
        {--lease-minutes=10 : Age of a processing lease (by processing_started_at) after which its holder is presumed dead}
        {--limit=200 : Maximum events to redispatch per pass in one run}';

    protected $description = 'Redispatch stalled pending Petpooja order events and reclaim expired processing leases (safety net only)';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $leaseMinutes = (int) $this->option('lease-minutes');
        $limit = (int) $this->option('limit');

        // Pass 1 — stalled pending. Ids are collected before pass 2 runs.
        $stalledPending = PosWebhookEvent::query()
            ->where('processing_status', PosWebhookEvent::STATUS_PENDING)
            ->where('event_type', 'orderdetails')
            ->where('received_at', '<=', now()->subMinutes($minutes))
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($stalledPending as $eventId) {
            ProcessPosWebhookEventJob::dispatch($eventId)->onQueue('restaurant');
        }

        // Pass 2 — expired processing leases. A NULL processing_started_at
        // never matches `<=`, so a row without a lease stamp is left alone
        // rather than guessed at.
        $cutoff = now()->subMinutes($leaseMinutes);

        $expired = PosWebhookEvent::query()
            ->where('processing_status', PosWebhookEvent::STATUS_PROCESSING)
            ->where('event_type', 'orderdetails')
            ->where('processing_started_at', '<=', $cutoff)
            ->orderBy('processing_started_at')
            ->limit($limit)
            ->pluck('id');

        $reclaimed = 0;

        foreach ($expired as $eventId) {
            $won = DB::table('pos_webhook_events')
                ->where('id', $eventId)
                ->where('processing_status', PosWebhookEvent::STATUS_PROCESSING)
                ->where('processing_started_at', '<=', $cutoff)
                ->update([
                    'processing_status' => PosWebhookEvent::STATUS_PENDING,
                    'failure_reason' => 'Processing lease expired; reclaimed by the stalled-event sweep.',
                    'updated_at' => now(),
                ]);

            if ($won !== 1) {
                continue;
            }

            $reclaimed++;

            Log::warning('restaurant.pos_webhook_event.lease_reclaimed', ['event_id' => $eventId]);

            ProcessPosWebhookEventJob::dispatch($eventId)->onQueue('restaurant');
        }

        $this->info("Redispatched {$stalledPending->count()} stalled pending pos_webhook_events row(s); reclaimed {$reclaimed} expired-lease processing row(s).");

        return self::SUCCESS;
    }
}
