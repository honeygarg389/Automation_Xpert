<?php

namespace App\Modules\SmartQr\Rules;

use App\Modules\SmartQr\Models\SmartQrBatch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * ⚠️ THE GAP SLICE 2 FOUND AND DEFERRED TO THIS SLICE.
 *
 * `smart_qr_codes.serial_number` is GLOBALLY unique, so a batch's
 * `prefix` + `serial_start` + `quantity` defines a range that must not overlap
 * any other batch's. Nothing prevented an administrator creating two batches
 * with prefix `AX` starting at 1.
 *
 * The failure was already SAFE — the unique index refuses, generation rolls back
 * and records the reason — but it arrived LATE: with a 500-code batch the
 * collision may not surface until the fifth chunk, after four have committed.
 * The operator saw a half-generated batch and a duplicate-key message rather
 * than "that range is taken".
 *
 * See `QrBatchGenerationTest::overlapping_serial_ranges_collide_at_generation_time`,
 * which nominated batch creation as the place for this check.
 *
 * ─── ⚠️ THIS IS A TOCTOU CHECK, AND IT IS NOT THE GUARANTEE ─────────────────
 *
 * Two admins submitting overlapping batches concurrently BOTH pass this rule:
 * it reads, then the row is written, and nothing holds a lock in between.
 *
 * **The unique index remains the actual guarantee.** This rule exists to turn a
 * late, cryptic, half-generated failure into an immediate and readable one — it
 * does not replace the constraint, and the slice-2 test proving generation still
 * fails safely is deliberately left passing and unchanged.
 */
class SerialRangeAvailable implements ValidationRule
{
    public function __construct(
        private readonly string $prefix,
        private readonly int $quantity,
        private readonly ?int $ignoreBatchId = null,
    ) {}

    /**
     * @param  Closure(string|\Stringable): (PotentiallyTranslatedString)  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $start = (int) $value;

        if ($start < 1 || $this->quantity < 1) {
            return;   // shape is another rule's job; do not double-report
        }

        $end = $start + $this->quantity - 1;

        // Overlap on half-open ranges: two ranges overlap iff each starts at or
        // before the other ends. Written as two comparisons rather than three
        // OR'd cases, which is where off-by-one errors live.
        $clash = SmartQrBatch::query()
            ->where('prefix', $this->prefix)
            ->when($this->ignoreBatchId !== null, fn ($q) => $q->whereKeyNot($this->ignoreBatchId))
            ->whereRaw('serial_start <= ?', [$end])
            ->whereRaw('(serial_start + quantity - 1) >= ?', [$start])
            ->first();

        if ($clash === null) {
            return;
        }

        // ⚠️ ONE LINE, AND IT STILL CARRIES BOTH ACTIONABLE FACTS: which
        // serials clashed, and which batch holds them.
        //
        // The version this replaces also restated the clashing batch's own
        // range and explained why serials are globally unique. Accurate, but it
        // rendered as a five-line red paragraph under the field and buried the
        // batch number — the one thing an operator needs to go look at. The
        // reasoning it spelled out lives in this class's docblock, which is
        // where a maintainer reads it; the operator does not need it mid-form.
        $fail(sprintf(
            'Serials %s-%06d to %s-%06d are already in use by batch %s.',
            $this->prefix, $start, $this->prefix, $end,
            $clash->batch_number,
        ));
    }
}
