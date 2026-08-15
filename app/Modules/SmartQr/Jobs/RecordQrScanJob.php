<?php

namespace App\Modules\SmartQr\Jobs;

use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Services\SmartQrScanFingerprint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Records one scan, off the request path (§8: "queue scan processing").
 *
 * ⚠️ ALREADY-HASHED VALUES ONLY. The constructor takes `ipHash` and `uaHash`,
 * never an address or a user agent. A raw IP therefore cannot reach the queue
 * payload, which is itself a durable store — `jobs` rows outlive the request and
 * would otherwise hold exactly the data §10 forbids persisting.
 *
 * ⚠️ NO WORKSPACE CONTEXT, and none is needed. The row is keyed by
 * `smart_qr_assignment_id` and the assignment carries the tenant (R-4). Declared
 * NO_TENANT_DATA in the Phase 0 job guard for that reason.
 */
class RecordQrScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $assignmentId,
        public readonly ?string $ipHash,
        public readonly ?string $uaHash,
        public readonly bool $isBot,
        public readonly ?string $refererHost,
        public readonly string $scannedAt,
    ) {}

    public function handle(): void
    {
        SmartQrScanEvent::create([
            'smart_qr_assignment_id' => $this->assignmentId,
            'scanned_at' => $this->scannedAt,
            'ip_hash' => $this->ipHash,
            'ua_hash' => $this->uaHash,
            'is_bot' => $this->isBot,
            'referer_host' => $this->refererHost,
            'is_unique' => $this->isUnique(),
        ]);
    }

    /**
     * ⚠️ Decided ONCE, here, and stored.
     *
     * Answering "was this unique" at read time means a self-join over what will
     * be the largest table in the system, on every dashboard load — which is
     * precisely what §10's daily aggregates exist to avoid.
     *
     * A null fingerprint never matches, so a visitor we cannot identify counts
     * as unique every time. That over-counts uniques, which is the safer error:
     * the alternative collapses every unidentifiable visitor into one.
     */
    private function isUnique(): bool
    {
        if ($this->ipHash === null && $this->uaHash === null) {
            return true;
        }

        // ⚠️ null needs whereNull, not where(col, null).
        //
        // `where('ip_hash', null)` builds `ip_hash = NULL`, which matches NOTHING
        // in SQL — so a visitor with one half of their fingerprint missing would
        // never match a previous hit and every scan would count as unique. Same
        // absent-vs-null conflation as BUG-030 and the GaugeReader filter.
        $match = fn ($q, string $column, ?string $value) => $value === null
            ? $q->whereNull($column)
            : $q->where($column, $value);

        $query = SmartQrScanEvent::query()
            ->where('smart_qr_assignment_id', $this->assignmentId)
            ->where('scanned_at', '>=', now()->subHours(SmartQrScanFingerprint::UNIQUE_WINDOW_HOURS));

        $query = $match($query, 'ip_hash', $this->ipHash);
        $query = $match($query, 'ua_hash', $this->uaHash);

        return ! $query->exists();
    }
}
