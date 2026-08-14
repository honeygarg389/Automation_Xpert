<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §6 assignment — R-9's single modal, as one flat set of fields.
 *
 * The spec describes ten sequential steps. Nothing in that flow requires
 * sequencing: no step's options depend on a later one, and the only dependency
 * — channel and user must belong to the chosen workspace — is a validation, not
 * an ordering. So the ten steps are the ten fields below.
 *
 * ⚠️ Cross-tenant validation (channel and user belong to the workspace) is NOT
 * here. It lives in SmartQrAssignmentValidator, because the assignment action
 * must enforce it too — a rule that exists only in a FormRequest is bypassed by
 * every caller that is not an HTTP request, including a future queued job.
 */
class AssignQrCodesRequest extends FormRequest
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
            // §6 step 1 — one or more. R-11 makes the whole set atomic.
            'code_ids' => ['required', 'array', 'min:1', 'max:200'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],

            // ⚠️ R-1 — the WORKSPACE, not the client. The spec says
            // "tenant/customer"; this codebase has both, and ChannelAccount is
            // workspace-scoped, so the workspace is the only answer that makes
            // "the channel belongs to the tenant" well-defined.
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],

            'channel_account_id' => ['required', 'integer', 'exists:channel_accounts,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],

            'name' => ['nullable', 'string', 'max:255'],
            'qr_type' => ['nullable', 'string', 'max:32'],
            'default_message' => ['nullable', 'string', 'max:1000'],

            // §6 step 8 — active/inactive. `ended` is not settable by hand: a
            // period ends by being unassigned, which is what releases the
            // current-assignment index.
            'status' => ['nullable', Rule::in([
                SmartQrStatus::ASSIGNMENT_ACTIVE,
                SmartQrStatus::ASSIGNMENT_INACTIVE,
            ])],

            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],

            // ⚠️ R-8 — REQUIRED WHEN OVERRIDING, and only then.
            //
            // `required_if` rather than `nullable`: the action's signature also
            // demands it, so this is the second of two gates rather than the
            // only one. An optional reason is an empty reason six weeks later.
            'override_limit' => ['nullable', 'boolean'],
            'override_reason' => ['required_if:override_limit,true,1', 'nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'override_reason.required_if' => 'Breaking a workspace\'s assignment limit requires a written reason. It is recorded in the audit log.',
            'override_reason.min' => 'Give a real reason — ten characters or more. "ok" tells the next reader nothing.',
        ];
    }
}
