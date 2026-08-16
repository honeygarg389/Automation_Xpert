<?php

namespace App\Modules\SmartQr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a batch. TWO fields, and the omissions are the point.
 *
 * ─── ⚠️ WHY `prefix`, `serial_start` AND `quantity` ARE ABSENT ──────────────
 *
 * Those three define the serial range, and the codes are already generated from
 * them. Editing them would leave every existing serial describing a range the
 * batch no longer claims — and `SerialRangeAvailable` would then police the new
 * range against other batches while the OLD serials sat in the table under the
 * old one. That is a regeneration, not a rename, and it has no path.
 *
 * ─── ⚠️ `batch_number` IS EDITABLE, AND THAT WAS CHECKED, NOT ASSUMED ───────
 *
 * Measured before allowing it — nothing depends on it as an identifier:
 *
 *   - the route key is `uuid`, not batch_number
 *   - serials are built from `prefix`, never from batch_number
 *     (`GenerateQrBatchAction::serialFor()`)
 *   - `SerialRangeAvailable` matches on `prefix` and reads batch_number ONLY to
 *     name the conflicting batch in its error message
 *   - zero `where('batch_number', …)` lookups anywhere in app/
 *   - it is not on the printed artwork; §14's artwork carries the SERIAL
 *
 * Uniqueness is still enforced, ignoring this row — it is the operator-facing
 * label for a print run and two runs sharing one would be its own confusion.
 */
class UpdateQrBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_name' => ['required', 'string', 'max:255'],
            'batch_number' => [
                'required', 'string', 'max:64',
                Rule::unique('smart_qr_batches', 'batch_number')->ignore($this->route('batch')?->id),
            ],
        ];
    }
}
