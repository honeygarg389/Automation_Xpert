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
     * The offset generation must RESUME FROM — one past the highest serial the
     * batch already holds, expressed as an offset from `serial_start`.
     *
     * ═══ ⚠️ WHY THIS IS NOT `codes()->count()` ══════════════════════════════
     *
     * It was, and that was a latent duplicate-key bug. Count and high-water mark
     * agree only while the serials form an unbroken run from `serial_start`, and
     * `QrInventoryController::destroy` hard-deletes selected codes —
     * `SmartQrCode` has no SoftDeletes, so a delete permanently removes a row
     * from the middle of the run:
     *
     *   serial_start=1 quantity=10, serials 1..10 exist, serial 5 is deleted
     *     count()            -> 9   -> resume at offset 9  -> serial 10  ✗ EXISTS
     *     highest serial     -> 10  -> resume at offset 10 -> serial 11  ✓
     *
     * `serial_number` is GLOBALLY unique, so the count-based answer does not
     * merely repeat a row — it violates the index, rolls the chunk back and
     * flips the whole batch to `failed` with a duplicate-key message.
     *
     * The bug was unreachable while nothing re-ran generation after creation.
     * Adding codes to an existing batch re-runs it deliberately, which is
     * exactly what would have woken it.
     *
     * ⚠️ A DELETED SERIAL IS NEVER REFILLED, and that is deliberate. The gap is
     * an administrator's explicit deletion; re-issuing that serial would print a
     * second sticker carrying a number a previous sticker already used.
     *
     * ⚠️ NUMERIC EXTRACTION, NOT `MAX(serial_number)`. The string maximum is only
     * the numeric maximum while every serial has the same digit count, and
     * `serial_start` has no upper bound in StoreQrBatchRequest — a batch starting
     * near 999999 crosses into 7 digits, where 'AX-1000000' sorts BELOW
     * 'AX-999999' and the resume point silently moves backwards.
     */
    public static function nextOffset(SmartQrBatch $batch): int
    {
        // +2 skips the prefix and the '-' separator (SUBSTRING is 1-indexed).
        $highest = $batch->codes()
            ->selectRaw(
                'MAX(CAST(SUBSTRING(serial_number, ?) AS UNSIGNED)) AS highest',
                [strlen((string) $batch->prefix) + 2]
            )
            ->value('highest');

        if ($highest === null) {
            return 0;
        }

        return max(0, (int) $highest - (int) $batch->serial_start + 1);
    }

    /**
     * @return int the number of codes actually committed
     *
     * @throws \Throwable rethrown after the failure is recorded
     */
    public function execute(SmartQrBatch $batch): int
    {
        $batch->forceFill(['status' => 'generating'])->save();

        // ⚠️ BOUNDED BY THE DECLARED RANGE, not by row count. The batch claims
        // serial_start .. serial_start + quantity - 1 and SerialRangeAvailable
        // polices that window against other batches — so generation must never
        // emit a serial past it. Topping up to restore a COUNT would do exactly
        // that after a deletion, spilling one serial into whatever batch owns
        // the next range.
        $nextOffset = self::nextOffset($batch);
        $remaining = max(0, (int) $batch->quantity - $nextOffset);
        $committed = 0;

        try {
            for ($start = $nextOffset; $start < $nextOffset + $remaining; $start += self::CHUNK) {
                $size = min(self::CHUNK, ($nextOffset + $remaining) - $start);

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

        $batch->forceFill([
            'status' => 'generated',

            // ⚠️ FIRST generation only. Re-running for an extension must not
            // restamp this — generated_at answers "when was this batch made",
            // and overwriting it on every top-up loses that permanently.
            'generated_at' => $batch->generated_at ?? now(),

            // ⚠️ CLEARED ON SUCCESS, because extension is allowed FROM `failed`.
            // Show.jsx renders failure_reason on its own truthiness, not gated
            // on status — so a stale reason paints a red error panel across a
            // batch that has just generated successfully.
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();

        return $committed;
    }
}
