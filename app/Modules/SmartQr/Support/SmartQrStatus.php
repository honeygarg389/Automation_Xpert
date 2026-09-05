<?php

namespace App\Modules\SmartQr\Support;

/**
 * ⚠️ R-10 — TWO STATUS VOCABULARIES, BECAUSE THEY ANSWER TWO QUESTIONS.
 *
 * The spec (§5) gives ONE list: generated, printed, assigned, active, inactive,
 * damaged, lost, retired. Slice 1 built TWO status columns, and the spec's list
 * splits across them — because those values describe two different rows with two
 * different lifetimes.
 *
 *   smart_qr_codes.status        what happened to the physical sticker
 *   smart_qr_assignments.status  whether the tenant's mapping is live
 *
 * ─── ⚠️ AND `assigned` IS IN NEITHER, DELIBERATELY ──────────────────────────
 *
 * Whether a code is assigned is ALREADY ANSWERED, exactly and atomically, by the
 * unique index over the `current_code_id` generated column. Storing it as a
 * status string beside that index would be a second source of truth beside a
 * DB-ENFORCED one — and the two would disagree the first moment an assignment
 * was written by a seeder, a raw insert, or a request that died between the two
 * writes. Then someone would "fix" whichever one they found first.
 *
 * That is the reasoning R-4 used to keep workspace_id off smart_qr_codes, and
 * the shape this codebase has been bitten by repeatedly: accessibleWorkspaces()
 * vs isAccessibleBy(), whatsapp_global vs whatsapp_msg, PlanLimits.jsx vs
 * defaultLimits(), activePlan() vs effectiveSubscription().
 *
 * `SmartQrStatusVocabularyTest` fails the build if a code row ever carries an
 * assignment word.
 */
final class SmartQrStatus
{
    // ── Physical lifecycle: what happened to the sticker ──────────────────

    public const CODE_GENERATED = 'generated';

    public const CODE_PRINTED = 'printed';

    public const CODE_DAMAGED = 'damaged';

    public const CODE_LOST = 'lost';

    public const CODE_RETIRED = 'retired';

    /** @var list<string> */
    public const CODE_STATUSES = [
        self::CODE_GENERATED,
        self::CODE_PRINTED,
        self::CODE_DAMAGED,
        self::CODE_LOST,
        self::CODE_RETIRED,
    ];

    /**
     * Physical states in which a code may NOT be assigned to a tenant.
     *
     * A retired, lost or damaged sticker either does not exist or must not be
     * pointed at a customer's WhatsApp number.
     *
     * @var list<string>
     */
    public const CODE_UNASSIGNABLE = [
        self::CODE_DAMAGED,
        self::CODE_LOST,
        self::CODE_RETIRED,
    ];

    // ── Assignment lifecycle: whether the mapping is live ─────────────────

    public const ASSIGNMENT_ACTIVE = 'active';

    public const ASSIGNMENT_INACTIVE = 'inactive';

    public const ASSIGNMENT_ENDED = 'ended';

    /** @var list<string> */
    public const ASSIGNMENT_STATUSES = [
        self::ASSIGNMENT_ACTIVE,
        self::ASSIGNMENT_INACTIVE,
        self::ASSIGNMENT_ENDED,
    ];

    /**
     * ⚠️ Words that must NEVER appear in `smart_qr_codes.status`.
     *
     * `assigned` because it is derived; `active`/`inactive` because they belong
     * to the assignment. A code carrying any of these is the second source of
     * truth R-10 forbids.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ON_CODE = ['assigned', 'active', 'inactive'];

    // ── Batch lifecycle (slice 2) ─────────────────────────────────────────

    public const BATCH_RETIRED = 'retired';

    /** @var list<string> */
    public const BATCH_STATUSES = ['draft', 'generating', 'generated', 'failed', 'printed', self::BATCH_RETIRED];
}
