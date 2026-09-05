import { Badge } from '@/Components/ui';
import { useTranslation } from 'react-i18next';

/**
 * ⚠️ R-10 — TWO VOCABULARIES, AND THIS COMPONENT KEEPS THEM APART.
 *
 * `smart_qr_codes.status` is PHYSICAL (generated, printed, damaged, lost,
 * retired). `smart_qr_assignments.status` is the mapping's liveness (active,
 * inactive, ended). The spec gives one list; the codebase has two columns.
 *
 * ⚠️ There is deliberately NO `assigned` entry here. Whether a code is assigned
 * is derived from the presence of `current_assignment` — the same question the
 * DB's unique index over `current_code_id` answers — and never read from a
 * status string. Rendering it as a status would put the second source of truth
 * back on the screen even though it is absent from the database.
 *
 * Uses the shared Badge, whose variants are default/success/warning/danger/brand.
 * No new colour tokens.
 */

const CODE_VARIANTS = {
    generated: 'default',
    printed: 'brand',
    damaged: 'warning',
    lost: 'warning',
    retired: 'danger',
};

const ASSIGNMENT_VARIANTS = {
    active: 'success',
    inactive: 'warning',
    ended: 'default',
};

const BATCH_VARIANTS = {
    draft: 'default',
    generating: 'brand',
    generated: 'success',
    printed: 'brand',
    failed: 'danger',
    retired: 'danger',
};

/**
 * ⚠️ A FOURTH VOCABULARY — smart_qr_exports.status, the liveness of one ZIP
 * PART. Kept beside the others rather than folded into them: it describes an
 * archive's build, not a code's physical life (CODE_*) nor a tenant mapping
 * (ASSIGNMENT_*) nor a print run (BATCH_*). R-10's whole point is that
 * separate questions get separate columns; sharing a variant map would be the
 * same conflation at the presentation layer.
 *
 * Mapped to the SAME semantics the other three use, so a colour means one
 * thing across this admin: `brand` for in-flight (matching batch `generating`),
 * `success` for the terminal good state, `danger` for the terminal bad one.
 */
const EXPORT_VARIANTS = {
    queued: 'default',
    processing: 'brand',
    ready: 'success',

    // ⚠️ `danger` for a genuine failure; `default` for expiry. An archive
    // reclaimed by retention did not fail — the export worked and its file was
    // later cleaned up. Colouring routine housekeeping red would train an admin
    // to ignore the colour that means something actually broke.
    failed: 'danger',
    expired: 'default',
};

export function CodeStatusBadge({ status }) {
    const { t } = useTranslation();

    return (
        <Badge variant={CODE_VARIANTS[status] ?? 'default'} size="sm">
            {t(`smart_qr.code_status.${status}`, status)}
        </Badge>
    );
}

export function AssignmentStatusBadge({ status }) {
    const { t } = useTranslation();

    return (
        <Badge variant={ASSIGNMENT_VARIANTS[status] ?? 'default'} size="sm">
            {t(`smart_qr.assignment_status.${status}`, status)}
        </Badge>
    );
}

/**
 * @param size defaults to 'sm' — the value this component hardcoded before, so
 * every existing call site renders exactly as it did. Only the batch detail
 * header opts into 'md', where the badge sits beside Button size="sm" controls
 * and has to match their text-sm.
 */
export function BatchStatusBadge({ status, size = 'sm' }) {
    const { t } = useTranslation();

    return (
        <Badge variant={BATCH_VARIANTS[status] ?? 'default'} size={size}>
            {t(`smart_qr.batch_status.${status}`, status)}
        </Badge>
    );
}

/**
 * One export part's status. See EXPORT_VARIANTS.
 */
export function ExportStatusBadge({ status, size = 'sm' }) {
    const { t } = useTranslation();

    return (
        <Badge variant={EXPORT_VARIANTS[status] ?? 'default'} size={size}>
            {t(`smart_qr.export_status.${status}`, status)}
        </Badge>
    );
}

/**
 * ⚠️ DERIVED, never a stored status. Takes the current assignment (or its
 * absence), not a string off the code row.
 */
export function AssignmentStateBadge({ currentAssignment }) {
    const { t } = useTranslation();

    return currentAssignment ? (
        <Badge variant="success" size="sm">{t('smart_qr.assigned')}</Badge>
    ) : (
        <Badge variant="default" size="sm">{t('smart_qr.unassigned')}</Badge>
    );
}
