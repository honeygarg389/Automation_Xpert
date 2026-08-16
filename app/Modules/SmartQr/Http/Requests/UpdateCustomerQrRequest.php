<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a CUSTOMER may change about their own QR. §11.
 *
 * ─── ⚠️ THE "CANNOT" LIST IS THE IMPORTANT HALF ─────────────────────────────
 *
 * §11 forbids the customer from transferring a QR to another tenant, editing
 * the public token, the serial number, or the system attribution token. None of
 * those appears below, so none is reachable — a field absent from the rules is
 * absent from `validated()` and therefore never reaches the update.
 *
 * `workspace_id` in particular: transferring is a REASSIGNMENT, which is an
 * admin act that closes one period and opens another (R-4). A customer form
 * that moved it would rewrite history rather than record a handover.
 *
 * ⚠️ `channel_account_id` IS here, unlike slice 3c's admin edit form which
 * deliberately excluded it. A customer switching WhatsApp lines is a real need
 * and the workaround — ask an admin — is worse than the feature. It is safe
 * only because SmartQrAssignmentValidator is reused IN FULL at the controller:
 * the channel must belong to the customer's own workspace, checked server-side.
 */
class UpdateCustomerQrRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'qr_type' => ['nullable', 'string', 'max:32'],
            'default_message' => ['nullable', 'string', 'max:1000'],

            // §11: activate/deactivate. `ended` is not settable — a period ends
            // by being unassigned, which is an admin act.
            'status' => ['nullable', Rule::in([
                SmartQrStatus::ASSIGNMENT_ACTIVE,
                SmartQrStatus::ASSIGNMENT_INACTIVE,
            ])],

            // Cross-workspace validity is enforced in the controller, not here:
            // a rule that exists only in a FormRequest is bypassed by every
            // caller that is not an HTTP request.
            'channel_account_id' => ['nullable', 'integer', 'exists:channel_accounts,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
