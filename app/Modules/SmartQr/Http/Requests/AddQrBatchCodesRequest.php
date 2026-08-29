<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Rules\SerialRangeAvailable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Extending an existing batch — the "more QR's" action.
 *
 * ─── ⚠️ WHY THIS IS NOT A FIELD ON UpdateQrBatchRequest ─────────────────────
 *
 * That request deliberately omits `prefix`, `serial_start` and `quantity`, and
 * its docblock explains why: editing them leaves the already-generated serials
 * describing a range the batch no longer claims. That reasoning is about
 * REWRITING the range and it still holds.
 *
 * Extending is a different operation: `prefix` and `serial_start` never move,
 * only the far end of the range advances, and every existing serial stays
 * inside it. Routing it through the rename endpoint would put a generation
 * dispatch behind a form whose stated contract is that it cannot cause one.
 *
 * ─── ⚠️ THE RANGE CHECK EXCLUDES THIS BATCH, AND MUST ───────────────────────
 *
 * SerialRangeAvailable matches any batch whose range overlaps. The batch being
 * extended overlaps ITSELF by definition, so without `ignoreBatchId` every
 * extension would be rejected as colliding with its own serials. The parameter
 * has existed since the rule was written and this is its first caller.
 *
 * ⚠️ It validates the FULL post-extension range, not just the new tail. The tail
 * alone is sufficient in principle — the existing portion was cleared at
 * creation — but "the range this batch will claim when I am done" is a
 * self-contained statement that stays correct if serial_start ever becomes
 * editable, where a tail-only check silently would not.
 */
class AddQrBatchCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function batch(): ?SmartQrBatch
    {
        $batch = $this->route('batch');

        return $batch instanceof SmartQrBatch ? $batch : null;
    }

    /**
     * ⚠️ serial_start is injected from the BATCH, overwriting anything the
     * client sent under that key. SerialRangeAvailable reads the attribute's
     * value as the range start, so it has to be present in the validated data —
     * but it is the batch's fact, never the submitter's, and merging last is
     * what makes a forged `serial_start` in the payload inert.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['serial_start' => $this->batch()?->serial_start]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $batch = $this->batch();
        $existing = $batch ? (int) $batch->quantity : 0;

        // The headroom left before the batch would exceed the ceiling. Expressed
        // as a max on the ADDITIONAL amount so the message names the number the
        // admin can actually type, rather than a total they must work out.
        $headroom = max(0, SmartQrBatch::MAX_QUANTITY - $existing);

        $newTotal = $existing + (int) $this->input('additional_quantity');

        return [
            'additional_quantity' => ['required', 'integer', 'min:1', 'max:'.$headroom],

            'serial_start' => [
                'required', 'integer', 'min:1',
                new SerialRangeAvailable(
                    $batch ? (string) $batch->prefix : '',
                    $newTotal,
                    $batch?->id,
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $batch = $this->batch();
        $existing = $batch ? (int) $batch->quantity : 0;

        return [
            'additional_quantity.max' => __(
                'This batch already has :existing code(s). You can add at most :headroom more '
                .'before it reaches the :max-code limit.',
                [
                    'existing' => $existing,
                    'headroom' => max(0, SmartQrBatch::MAX_QUANTITY - $existing),
                    'max' => SmartQrBatch::MAX_QUANTITY,
                ]
            ),
        ];
    }
}
