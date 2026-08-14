<?php

namespace App\Modules\SmartQr\Actions;

use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates a batch's codes.
 *
 * ─── ⚠️ WHY THE FAILURE RECORD IS WRITTEN OUTSIDE THE TRANSACTION ───────────
 *
 * The obvious shape — wrap everything, and on failure write `failure_reason`
 * before rethrowing — DOES NOT WORK. The write would be inside the transaction
 * being rolled back, so the reason vanishes with the codes and the batch is left
 * looking untouched. The operator sees a batch stuck at `generating` with no
 * explanation, which is worse than no rollback at all: silent, and
 * indistinguishable from a crashed worker.
 *
 * So the transaction covers ONLY the inserts, and the failure record is written
 * after it has rolled back. That ordering is the whole point and it is asserted
 * by test.
 *
 * ─── Chunking ───────────────────────────────────────────────────────────────
 *
 * One transaction per chunk, not one for the batch. A 500-code batch in a single
 * transaction holds locks for its whole duration and is one failure away from
 * losing everything. Per-chunk means a failure loses at most one chunk's work —
 * and `generated_count` advances only on commit, so it always reflects rows that
 * actually exist rather than rows we intended to write.
 */
class GenerateQrBatchAction
{
    public const CHUNK = 100;

    /** Serial = prefix + zero-padded (serial_start + offset). */
    public static function serialFor(SmartQrBatch $batch, int $offset): string
    {
        return sprintf('%s-%06d', $batch->prefix, $batch->serial_start + $offset);
    }

    /**
     * ⚠️ Cryptographically secure and NON-SEQUENTIAL.
     *
     * `random_bytes` — not `uniqid`, not `Str::random` seeded from mt_rand, and
     * never anything derived from the serial or the row id. This token addresses
     * a tenant from an unauthenticated request: a guessable one lets a stranger
     * enumerate every customer's QR destination.
     *
     * 32 hex chars = 128 bits. Collision is not the failure mode being defended
     * against here — the unique index is — but the entropy makes a collision
     * astronomically less likely than the constraint firing for any other reason.
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return int the number of codes actually committed
     *
     * @throws \Throwable rethrown after the failure is recorded
     */
    public function execute(SmartQrBatch $batch): int
    {
        $batch->forceFill(['status' => 'generating'])->save();

        $already = (int) $batch->codes()->count();
        $remaining = max(0, (int) $batch->quantity - $already);
        $committed = 0;

        try {
            for ($start = $already; $start < $already + $remaining; $start += self::CHUNK) {
                $size = min(self::CHUNK, ($already + $remaining) - $start);

                // One transaction per chunk. generated_count is advanced INSIDE
                // it, so it commits or rolls back with the rows it counts —
                // never optimistically ahead of them.
                DB::transaction(function () use ($batch, $start, $size, &$committed) {
                    $rows = [];
                    for ($i = 0; $i < $size; $i++) {
                        $rows[] = [
                            'serial_number' => self::serialFor($batch, $start + $i),
                            'public_token' => self::token(),
                            'batch_id' => $batch->id,
                            'status' => 'generated',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    SmartQrCode::insert($rows);

                    $batch->increment('generated_count', $size);
                    $committed += $size;
                });
            }
        } catch (\Throwable $e) {
            // ⚠️ OUTSIDE the rolled-back transaction. See the class docblock.
            $batch->forceFill([
                'status' => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 500),
                'failed_at' => now(),
            ])->save();

            throw $e;
        }

        $batch->forceFill(['status' => 'generated', 'generated_at' => now()])->save();

        return $committed;
    }
}
