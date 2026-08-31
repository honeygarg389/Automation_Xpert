<?php

namespace App\Modules\SmartQr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Locking a QR assignment's active status.
 *
 * ⚠️ `lock_reason` IS REQUIRED, not optional. CLAUDE.md §9: a manual override
 * needs permission + reason + dates + audit log. This is the reason, and it is
 * the only part a person has to supply — the other three are supplied by the
 * route gate, the timestamp and AuditLogService.
 *
 * It is also the message a customer is shown when their toggle is refused, so
 * an empty one produces "Reason: ." on a screen belonging to somebody who is
 * already frustrated. `min:5` rejects "x" and "." without pretending to judge
 * whether the sentence is useful.
 */
class LockQrAssignmentRequest extends FormRequest
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
            'lock_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lock_reason.required' => __('A reason is required — the customer is shown it when their toggle is refused.'),
            'lock_reason.min' => __('Please give a reason the customer can act on.'),
        ];
    }
}
