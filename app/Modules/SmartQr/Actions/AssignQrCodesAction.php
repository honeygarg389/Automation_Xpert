<?php

namespace App\Modules\SmartQr\Actions;

use App\Models\AdminUser;
use App\Models\Workspace;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrAssignmentCapacity;
use App\Modules\SmartQr\Services\SmartQrAssignmentValidator;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assigns one or more codes to a workspace. R-1, R-8, R-10, R-11.
 *
 * ─── ⚠️ R-11 — ALL OR NOTHING, AND WHY THE SHAPE MATTERS ────────────────────
 *
 * The capacity check is made ONCE, for the whole request, BEFORE the loop —
 * never per code inside it. A per-code check inside the loop IS the partial
 * implementation: it assigns three, refuses the fourth, and returns an error
 * having already committed three. The admin reads "failed" and three codes are
 * live.
 *
 * The transaction is the second half of the same guarantee: anything that
 * fails mid-loop (a code assigned by a concurrent request, a channel deleted
 * between validation and write) takes the whole batch back out with it.
 *
 * ⚠️ A test asserting only "an error was returned" passes against the partial
 * implementation. The assertion that discriminates is ZERO ROWS WRITTEN.
 *
 * ─── ⚠️ R-8 — THE OVERRIDE REASON IS REQUIRED AT THE SIGNATURE ──────────────
 *
 * `$overrideReason` is a non-nullable `string` on `assignOverridingLimit()`,
 * not a nullable parameter here and not a nullable column on the table. An
 * optional reason is an empty reason six weeks later, and then nobody knows why
 * a limit was broken. Same reasoning as `withoutWorkspaceScope('reason: …')`
 * taking its argument rather than documenting it: a rule that depends on
 * remembering is not a rule.
 *
 * The reason has nowhere to live on the row — R-8 explicitly forbids the column
 * — so it goes to the audit log, which is where "who broke a limit and why"
 * belongs anyway.
 */
class AssignQrCodesAction
{
    public function __construct(
        private readonly SmartQrAssignmentCapacity $capacity,
        private readonly SmartQrAssignmentValidator $validator,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * The normal path: refuses when the workspace is at its limit.
     *
     * @param  list<int>  $codeIds
     * @param  array<string, mixed>  $attributes
     * @return list<SmartQrAssignment>
     *
     * @throws RuntimeException when refused — nothing is written
     */
    public function assign(
        Workspace $workspace,
        array $codeIds,
        array $attributes,
        ?AdminUser $admin = null,
    ): array {
        if (! $this->capacity->allows($workspace, count($codeIds))) {
            // ⚠️ Thrown BEFORE the transaction opens, so there is nothing to
            // roll back and no window in which a row exists.
            throw new RuntimeException(
                $this->capacity->refusalMessage($workspace, count($codeIds))
            );
        }

        return $this->write($workspace, $codeIds, $attributes, $admin, null);
    }

    /**
     * ⚠️ R-8 — the override. Permission is checked by the ROUTE; the reason is
     * required HERE, by the signature, so no caller can reach this without one.
     *
     * @param  list<int>  $codeIds
     * @param  array<string, mixed>  $attributes
     * @param  string  $overrideReason  REQUIRED. Why this limit is being broken.
     * @return list<SmartQrAssignment>
     */
    public function assignOverridingLimit(
        Workspace $workspace,
        array $codeIds,
        array $attributes,
        string $overrideReason,
        ?AdminUser $admin = null,
    ): array {
        if (trim($overrideReason) === '') {
            throw new \InvalidArgumentException(
                'An over-limit assignment requires a non-empty reason. A limit broken without a '
                .'stated reason is indistinguishable from a mistake.'
            );
        }

        return $this->write($workspace, $codeIds, $attributes, $admin, $overrideReason);
    }

    /**
     * @param  list<int>  $codeIds
     * @param  array<string, mixed>  $attributes
     * @return list<SmartQrAssignment>
     */
    private function write(
        Workspace $workspace,
        array $codeIds,
        array $attributes,
        ?AdminUser $admin,
        ?string $overrideReason,
    ): array {
        if ($codeIds === []) {
            throw new RuntimeException('Select at least one QR code to assign.');
        }

        // Validated once, before anything is written. Both checks are for the
        // whole request — a channel that belongs to another workspace is not
        // "mostly fine".
        if (! $this->validator->channelIsAssignable($workspace, $attributes['channel_account_id'] ?? null)) {
            throw new RuntimeException(
                'That WhatsApp channel does not belong to the selected workspace, or is not '
                .'connected. A QR pointing at a dead channel is a printed sticker that goes '
                .'nowhere.'
            );
        }

        if (! $this->validator->userBelongsToWorkspace($workspace, $attributes['assigned_user_id'] ?? null)) {
            throw new RuntimeException('That user is not a member of the selected workspace.');
        }

        // ⚠️ ONE transaction for the whole batch (R-11). Not one per code.
        $assignments = DB::transaction(function () use ($workspace, $codeIds, $attributes, $admin) {
            $written = [];

            foreach ($codeIds as $codeId) {
                $code = SmartQrCode::find($codeId);

                if ($code === null) {
                    throw new RuntimeException("QR code #{$codeId} no longer exists.");
                }

                // ⚠️ R-10: the PHYSICAL state is what blocks assignment. There
                // is no `assigned` status to consult — that question is answered
                // by the current-assignment index below.
                if (in_array($code->status, SmartQrStatus::CODE_UNASSIGNABLE, true)) {
                    throw new RuntimeException(
                        "QR code {$code->serial_number} is marked {$code->status} and cannot be assigned."
                    );
                }

                // ⚠️ Read WITHOUT the workspace scope. The question is "is this
                // code held by ANYONE", and a scoped query in an admin request
                // with no context answers "no" for every code — which would let
                // one code be assigned to two tenants and leave the DB's unique
                // index as the only thing standing in the way.
                $existing = SmartQrAssignment::withoutWorkspaceScope(
                    'reason: admin reassignment must see the CURRENT holder across all '
                    .'workspaces; a scoped read would report every code unheld'
                )
                    ->where('smart_qr_code_id', $code->id)
                    ->whereNull('unassigned_at')
                    ->first();

                if ($existing !== null) {
                    throw new RuntimeException(
                        "QR code {$code->serial_number} is already assigned. Unassign it first — "
                        .'reassigning silently would hide the previous period from whoever is '
                        .'reading the analytics.'
                    );
                }

                $assignment = new SmartQrAssignment;
                $assignment->forceFill([
                    'smart_qr_code_id' => $code->id,
                    'workspace_id' => $workspace->id,
                    'channel_account_id' => $attributes['channel_account_id'],
                    'assigned_user_id' => $attributes['assigned_user_id'] ?? null,
                    'name' => $attributes['name'] ?? null,
                    'qr_type' => $attributes['qr_type'] ?? null,
                    'default_message' => $attributes['default_message'] ?? null,
                    'status' => $attributes['status'] ?? SmartQrStatus::ASSIGNMENT_ACTIVE,
                    'starts_at' => $attributes['starts_at'] ?? null,
                    'expires_at' => $attributes['expires_at'] ?? null,
                    'assigned_by_admin_id' => $admin?->id,
                    // The terms in force at assignment, so a later plan change
                    // does not rewrite what this QR was sold as.
                    'config_snapshot' => [
                        'assigned_at' => now()->toIso8601String(),
                        'channel_account_id' => $attributes['channel_account_id'],
                    ],
                ]);
                $assignment->save();

                $written[] = $assignment;
            }

            return $written;
        });

        $this->audit->logAdmin(
            $overrideReason === null ? 'smart_qr.assigned' : 'smart_qr.assigned_over_limit',
            SmartQrAssignment::class,
            $assignments[0]->id ?? null,
            array_filter([
                'workspace_id' => $workspace->id,
                'code_ids' => $codeIds,
                'count' => count($codeIds),
                // ⚠️ R-8: the reason, and the numbers it was granted against.
                // Without the numbers "he had a reason" is unauditable.
                'override_reason' => $overrideReason,
                'capacity' => $overrideReason === null
                    ? null
                    : $this->capacity->snapshot($workspace, count($codeIds)),
            ], fn ($v) => $v !== null),
            $admin,
        );

        return $assignments;
    }
}
