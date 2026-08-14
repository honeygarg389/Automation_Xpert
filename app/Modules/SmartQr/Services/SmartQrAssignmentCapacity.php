<?php

namespace App\Modules\SmartQr\Services;

use App\Models\Workspace;
use App\Modules\Entitlements\Services\GaugeReader;
use App\Modules\Entitlements\Support\Entitlements;

/**
 * ⚠️ R-5/R-8 — THE ONE PLACE THE ASSIGNMENT LIMIT IS DECIDED.
 *
 * R-5 puts three Smart QR entitlements in three different places, of three
 * different kinds. This is the GAUGE — `smart_qr_max_assigned`, enforced at
 * assignment, counted as COUNT(*) of CURRENT assignments.
 *
 * Nothing is consumed at generation: codes are platform inventory and
 * `smart_qr_codes` has no workspace to resolve an entitlement for.
 */
class SmartQrAssignmentCapacity
{
    public const KEY = 'smart_qr_max_assigned';

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly GaugeReader $gauge,
    ) {}

    /**
     * How many current assignments this workspace holds.
     *
     * Goes through GaugeReader rather than counting here, so the
     * `unassigned_at IS NULL` filter has exactly one definition — the one
     * declared in GaugeSources and proven by the R-7 discriminator.
     */
    public function current(Workspace $workspace): int
    {
        return (int) $this->gauge->count(self::KEY, $workspace);
    }

    /**
     * The ceiling. `null` means unlimited.
     *
     * ⚠️ null and 0 are OPPOSITE extremes, and this project has repeatedly
     * assumed otherwise:
     *
     *     limit null  -> no ceiling      -> unlimited
     *     limit 0     -> bounded at zero -> the first assignment is REFUSED
     *
     * That is BUG-030's pinned semantics. Do not add a `?: null` anywhere near
     * this value.
     */
    public function limit(Workspace $workspace): ?int
    {
        return $this->entitlements->limitForWorkspace($workspace->id, self::KEY);
    }

    /**
     * ⚠️ R-11 — ALL OR NOTHING. The answer is for the WHOLE request.
     *
     * Assigning 5 codes into 3 remaining slots is refused entirely, not
     * satisfied three-fifths. Partial assignment is the worst outcome available:
     * the admin believes five landed, three did, and nobody finds out until a
     * customer reports a QR that goes nowhere.
     *
     * So `$requested` is part of the sum rather than something the caller loops
     * over — a per-code check inside a loop IS the partial implementation.
     */
    public function allows(Workspace $workspace, int $requested): bool
    {
        $limit = $this->limit($workspace);

        if ($limit === null) {
            return true;
        }

        return ($this->current($workspace) + $requested) <= $limit;
    }

    /**
     * The refusal message. R-8 requires the count to be IN the error — an
     * admin who is refused without being told the numbers cannot tell a limit
     * from a bug.
     */
    public function refusalMessage(Workspace $workspace, int $requested): string
    {
        $limit = $this->limit($workspace);
        $current = $this->current($workspace);

        return sprintf(
            'This workspace holds %d of %d assigned QR codes and cannot take %d more. '
            .'Free a code by unassigning one, raise the plan limit, or use the override.',
            $current,
            (int) $limit,
            $requested
        );
    }

    /**
     * Everything the UI and the audit log need, in one shape.
     *
     * @return array{limit: int|null, current: int, requested: int, remaining: int|null}
     */
    public function snapshot(Workspace $workspace, int $requested = 0): array
    {
        $limit = $this->limit($workspace);
        $current = $this->current($workspace);

        return [
            'limit' => $limit,
            'current' => $current,
            'requested' => $requested,
            'remaining' => $limit === null ? null : max(0, $limit - $current),
        ];
    }
}
