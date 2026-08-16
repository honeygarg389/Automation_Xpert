<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a live assignment's per-tenant settings.
 *
 * ─── ⚠️ WHAT IS ABSENT, AND WHY EACH ABSENCE IS LOAD-BEARING ────────────────
 *
 * `serial_number` — printed on a physical object. Editing it would rename a
 *   sticker that is already on somebody's counter. §11 forbids it and R-4's
 *   whole model assumes it is stable.
 *
 * `public_token` — the secret the QR encodes. Changing it silently breaks every
 *   printed copy of that code, and it addresses a tenant from an
 *   unauthenticated request.
 *
 * `workspace_id` — changing the tenant IS a reassignment. It has its own path
 *   (unassign, then assign) which closes the old period so the previous
 *   tenant's scans stay reachable and the DB's one-current-assignment index
 *   stays satisfiable. Reaching it through an edit form would move a code
 *   between tenants while leaving a single assignment row claiming it had always
 *   belonged to the new one — silently rewriting history.
 *
 * `channel_account_id` is also absent: it is validated against the workspace at
 * assignment time, and changing it here would need that cross-tenant check
 * repeated. Left to the reassignment path deliberately rather than half-done.
 */
class UpdateQrAssignmentRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([
                SmartQrStatus::ASSIGNMENT_ACTIVE,
                SmartQrStatus::ASSIGNMENT_INACTIVE,
            ])],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
