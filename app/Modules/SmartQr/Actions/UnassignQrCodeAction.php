<?php

namespace App\Modules\SmartQr\Actions;

use App\Models\AdminUser;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;

/**
 * Ends an assignment period. R-4.
 *
 * ⚠️ CLOSES THE PERIOD, NEVER DELETES THE ROW.
 *
 * The spec requires that a reassigned QR hide the previous tenant's analytics,
 * and that is only expressible if the old period survives: scans are keyed by
 * `smart_qr_assignment_id`, so the old scans stay attached to the old row, which
 * carries the old workspace_id and is invisible to the new tenant's scoped
 * queries. Deleting the row would take the previous tenant's own history with it
 * — hiding it from the NEW tenant is the requirement, deleting it is not.
 *
 * Setting `unassigned_at` also releases the DB's unique index over
 * `current_code_id` (it becomes NULL, and NULLs do not collide), so the code
 * becomes assignable again without any row being removed.
 */
class UnassignQrCodeAction
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function execute(SmartQrAssignment $assignment, ?AdminUser $admin = null): SmartQrAssignment
    {
        if (! $assignment->isCurrent()) {
            return $assignment;   // idempotent: an ended period stays ended
        }

        $assignment->forceFill([
            'unassigned_at' => now(),
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
        ])->save();

        $this->audit->logAdmin(
            'smart_qr.unassigned',
            SmartQrAssignment::class,
            $assignment->id,
            [
                'workspace_id' => $assignment->workspace_id,
                'smart_qr_code_id' => $assignment->smart_qr_code_id,
            ],
            $admin,
        );

        return $assignment;
    }
}
