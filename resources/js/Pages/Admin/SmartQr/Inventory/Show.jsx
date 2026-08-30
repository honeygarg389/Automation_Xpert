import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Dropdown } from '@/Components/ui';
import { ArrowLeft, Check, Copy, Download, Layers, Link2, Pencil, Tag, Unlink } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CodeStatusBadge, AssignmentStateBadge, AssignmentStatusBadge } from '../QrStatusBadge';
import AssignQrModal from '../AssignQrModal';
import EditAssignmentModal from '../EditAssignmentModal';
import { formatDateTz } from '@/Utils/datetime';

/**
 * One QR code's detail page. §5.
 *
 * ═══ ⚠️ WHY THE NAME / TYPE / MESSAGE FIELDS ARE CONDITIONAL ════════════════
 *
 * The reference design shows an editable name under the serial and a QR Type in
 * the Overview panel, as though both belonged to the code. They do not.
 * `smart_qr_codes` has exactly: serial_number, public_token, batch_id, status,
 * printed_at. Name, qr_type and default_message live on the ASSIGNMENT.
 *
 * That is deliberate, not an oversight to work around: a code is physical
 * inventory that outlives any one tenancy, and a sticker recycled to a new
 * customer must not carry the previous customer's label. The same reasoning
 * removed qr_type from batch creation in an earlier slice.
 *
 * So an unassigned code shows a one-line hint where those fields would be,
 * rather than empty inputs. Empty inputs would imply the data has somewhere to
 * be stored at inventory level, and the first admin to type into one would be
 * owed an explanation the UI cannot give.
 */

/** Rows in the Overview / Assignment panels — one definition, so they align. */
function Field({ label, children }) {
    return (
        <div className="flex items-start justify-between gap-4 py-2">
            <span className="shrink-0 text-sm text-neutral-500 dark:text-neutral-400">{label}</span>
            <span className="min-w-0 break-words text-right text-sm font-medium text-neutral-900 dark:text-neutral-100">
                {children ?? '—'}
            </span>
        </div>
    );
}

/**
 * ⚠️ Falls back to a manual selection rather than failing silently.
 * navigator.clipboard is undefined on insecure origins — which includes the
 * http://127.0.0.1:8000 an admin runs locally — so a bare call there throws and
 * the button appears to do nothing.
 */
function CopyButton({ value }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            window.prompt(t('smart_qr.field_public_url'), value);
        }
    };

    return (
        <button
            type="button"
            onClick={copy}
            title={copied ? t('smart_qr.copied') : t('smart_qr.copy')}
            aria-label={copied ? t('smart_qr.copied') : t('smart_qr.copy')}
            className="shrink-0 rounded p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
        >
            {copied ? <Check className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
        </button>
    );
}

export default function SmartQrCodeShow({
    code,
    batch = null,
    currentAssignment = null,
    statuses = [],
    exportFormats = [],
    workspaces = [],
}) {
    const { t } = useTranslation();
    const page = usePage();
    const adminTz = page.props.timezone || 'UTC';
    const flash = page.props.flash || {};
    const permissions = page.props.auth?.permissions ?? [];

    const canManage = permissions.includes('manage_qr_batches');
    const canAssign = permissions.includes('assign_qr_codes');

    const [assigning, setAssigning] = useState(false);
    const [staging, setStaging] = useState(false);
    const [unassigning, setUnassigning] = useState(false);
    const [editing, setEditing] = useState(false);

    /**
     * ⚠️ REUSES THE BULK ENDPOINT with a one-element array. changeStatus()
     * validates `code_ids` as array|min:1, so a single code needs no new route —
     * and a second endpoint would be a second place for the status vocabulary to
     * drift from SmartQrStatus::CODE_STATUSES.
     */
    const changeStage = (status) => {
        setStaging(true);
        router.post(
            route('admin.qr.inventory.change-status'),
            { code_ids: [code.id], status },
            { preserveScroll: true, onFinish: () => setStaging(false) },
        );
    };

    /**
     * ⚠️ REUSES admin.qr.assignments.destroy — the same endpoint the Assignments
     * list calls, keyed on the ASSIGNMENT's uuid (its route key), not the code's
     * serial. No new backend: unassigning from here and from there must not be
     * two code paths that can disagree about what unassign means.
     *
     * ⚠️ Confirmed first. Unassigning ends a live mapping — a scan of a sticker
     * already in a customer's hand stops resolving — and there is no undo button.
     */
    const unassign = () => {
        if (! window.confirm(t('smart_qr.unassign_confirm', { serial: code.serial_number }))) return;

        setUnassigning(true);
        router.delete(route('admin.qr.assignments.destroy', currentAssignment.uuid), {
            preserveScroll: true,
            onFinish: () => setUnassigning(false),
        });
    };

    return (
        <AdminLayout title={code.serial_number}>
            <Head title={`${code.serial_number} · ${t('head.admin')}`} />

            <div className="space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 px-4 py-2 text-sm text-green-800 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div>
                    <Link
                        href={route('admin.qr.inventory.index')}
                        className="mb-3 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" /> {t('smart_qr.back_to_inventory')}
                    </Link>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div>
                                <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                    {t('smart_qr.qr_detail_title', { serial: code.serial_number })}
                                </h2>
                                {/* ⚠️ The assignment's name, and only when there IS
                                    an assignment — see the file docblock. */}
                                {currentAssignment?.name && (
                                    <p className="mt-0.5 text-sm leading-tight text-neutral-500 dark:text-neutral-400">
                                        {currentAssignment.name}
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Action group, matching Batches/Show.jsx's right-hand
                            cluster: one flex container so the controls stay
                            adjacent and wrap together. */}
                        <div className="flex flex-wrap items-center gap-3">
                            {/* ⚠️ MUTUALLY EXCLUSIVE, keyed on the same value the
                                Assignment panel below reads. Mirrors how the batch
                                detail header hides Retire rather than disabling it:
                                a disabled control invites "why can't I?", and the
                                answer here is already visible one panel down. */}
                            {canAssign && ! currentAssignment && (
                                <Button variant="outline" size="sm" onClick={() => setAssigning(true)}>
                                    <Link2 className="mr-1.5 h-4 w-4" /> {t('smart_qr.assign_qr')}
                                </Button>
                            )}

                            {canAssign && currentAssignment && (
                                <Button variant="outline" size="sm" disabled={unassigning} onClick={unassign}>
                                    <Unlink className="mr-1.5 h-4 w-4" /> {t('smart_qr.unassign_qr')}
                                </Button>
                            )}

                            {canManage && (
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <Button variant="outline" size="sm" disabled={staging}>
                                            <Tag className="mr-1.5 h-4 w-4" /> {t('smart_qr.change_stage')}
                                        </Button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content width="56">
                                        {statuses.map((s) => (
                                            <Dropdown.Item key={s} as="button" onClick={() => changeStage(s)}>
                                                {t(`smart_qr.code_status.${s}`, s)}
                                            </Dropdown.Item>
                                        ))}
                                    </Dropdown.Content>
                                </Dropdown>
                            )}

                            <Link href={route('admin.qr.batches.index')}>
                                <Button variant="outline" size="sm">
                                    <Layers className="mr-1.5 h-4 w-4" /> {t('smart_qr.view_batches')}
                                </Button>
                            </Link>
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <Card>
                            <h3 className="mb-2 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                {t('smart_qr.section_overview')}
                            </h3>
                            <div className="divide-y divide-neutral-100 dark:divide-neutral-700">
                                <Field label={t('smart_qr.field_status')}>
                                    <CodeStatusBadge status={code.status} />
                                </Field>
                                <Field label={t('smart_qr.col_assignment')}>
                                    <AssignmentStateBadge currentAssignment={currentAssignment} />
                                </Field>
                                <Field label={t('smart_qr.field_batch')}>
                                    {/* ⚠️ The batch route key is `uuid`, not id —
                                        passing id 404s at route binding. */}
                                    {batch ? (
                                        <Link
                                            href={route('admin.qr.batches.show', batch.uuid)}
                                            className="text-brand-600 hover:underline dark:text-brand-400"
                                        >
                                            {batch.batch_number}
                                        </Link>
                                    ) : null}
                                </Field>
                                {/* ⚠️ ASSIGNMENT-SCOPED, so it appears only when
                                    assigned — like name / type / message below.
                                    Position is deliberate: it sits between Batch and
                                    Printed status, matching the reference.

                                    ⚠️ This is the ASSIGNMENT's active/inactive state
                                    (R-10's second vocabulary), NOT the code's
                                    physical status in the row above. Two different
                                    questions that both use the word "status", which
                                    is exactly why they get different labels and
                                    different badges. */}
                                {currentAssignment && (
                                    <Field label={t('smart_qr.field_active_status')}>
                                        <AssignmentStatusBadge status={currentAssignment.status} />
                                    </Field>
                                )}
                                <Field label={t('smart_qr.field_printed')}>
                                    {/* ⚠️ THE SAME `OR` SmartQrDeletability::everPrinted()
                                        USES, and for the same reason: two columns
                                        answer "was this printed" and a display
                                        trusting one can contradict the badge beside
                                        it. printed_at may be null on a code whose
                                        status is `printed` (historic rows written
                                        before changeStatus() recorded the event), and
                                        status may have moved on from `printed` while
                                        printed_at legitimately remains.

                                        The date is shown only when there IS one — a
                                        code known printed without a timestamp says so
                                        without inventing a date. */}
                                    {(code.printed_at || code.status === 'printed')
                                        ? [t('smart_qr.printed_yes'), code.printed_at && formatDateTz(code.printed_at, adminTz)]
                                            .filter(Boolean).join(' · ')
                                        : t('smart_qr.printed_no')}
                                </Field>
                                <Field label={t('smart_qr.field_public_url')}>
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="break-all font-mono text-xs">{code.public_url}</span>
                                        <CopyButton value={code.public_url} />
                                    </span>
                                </Field>
                                <Field label={t('smart_qr.field_created')}>
                                    {formatDateTz(code.created_at, adminTz)}
                                </Field>
                            </div>
                        </Card>

                        <Card>
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                    {t('smart_qr.section_assignment')}
                                </h3>

                                {/* ⚠️ ONLY WHEN ASSIGNED — there is nothing to edit
                                    otherwise, since every field this opens lives on
                                    the assignment row.

                                    ⚠️ assign_qr_codes, matching Assign/Unassign: it
                                    edits assignment data, so it belongs on the
                                    assignment gate, not manage_qr_batches. */}
                                {canAssign && currentAssignment && (
                                    <button
                                        type="button"
                                        onClick={() => setEditing(true)}
                                        title={t('smart_qr.edit_qr')}
                                        aria-label={t('smart_qr.edit_qr')}
                                        className="shrink-0 rounded p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                    >
                                        <Pencil className="h-4 w-4" />
                                    </button>
                                )}
                            </div>

                            {currentAssignment ? (
                                <div className="divide-y divide-neutral-100 dark:divide-neutral-700">
                                    <Field label={t('smart_qr.field_destination_phone')}>
                                        {currentAssignment.destination_phone}
                                    </Field>
                                    <Field label={t('smart_qr.field_workspace')}>
                                        {currentAssignment.workspace_name}
                                    </Field>
                                    <Field label={t('smart_qr.field_name')}>{currentAssignment.name}</Field>
                                    <Field label={t('smart_qr.field_qr_type')}>{currentAssignment.qr_type}</Field>
                                    <Field label={t('smart_qr.field_assigned_user')}>
                                        {currentAssignment.assigned_user_name ?? t('smart_qr.no_assigned_user')}
                                    </Field>
                                    <Field label={t('smart_qr.field_message_override')}>
                                        {currentAssignment.default_message}
                                    </Field>
                                    <Field label={t('smart_qr.field_start_date')}>
                                        {currentAssignment.starts_at ? formatDateTz(currentAssignment.starts_at, adminTz) : null}
                                    </Field>
                                    <Field label={t('smart_qr.field_expiry_date')}>
                                        {currentAssignment.expires_at ? formatDateTz(currentAssignment.expires_at, adminTz) : null}
                                    </Field>
                                </div>
                            ) : (
                                /* ⚠️ A HINT, NOT EMPTY FIELDS. Name/type/message have
                                   nowhere to live until an assignment exists — see
                                   the file docblock. */
                                <div className="py-2">
                                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                        {t('smart_qr.assignment_empty_hint')}
                                    </p>
                                    {canAssign && (
                                        <Button variant="outline" size="sm" className="mt-3" onClick={() => setAssigning(true)}>
                                            <Link2 className="mr-1.5 h-4 w-4" /> {t('smart_qr.bulk_assign')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </Card>
                    </div>

                    <Card>
                        <h3 className="mb-3 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                            {t('smart_qr.qr_preview')}
                        </h3>
                        {/* ⚠️ The ADMIN preview route. The customer one
                            (client.smartqr.codes.preview) resolves through
                            findForWorkspace() and 404s for an admin on any code
                            outside their own workspace — which is most of them. */}
                        <img
                            src={route('admin.qr.inventory.preview', code.serial_number)}
                            alt={code.serial_number}
                            className="mx-auto w-full max-w-[260px] rounded-soft border border-neutral-200 dark:border-neutral-700"
                        />
                        <p className="mt-3 text-center text-xs text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.scan_to_test')}
                        </p>

                        {/* ⚠️ MOVED OUT OF THE HEADER. These three sit under the
                            image they produce, so the thing being downloaded is
                            visible while choosing a format — the header dropdown
                            put the choice three panels away from its subject.

                            ⚠️ Plain anchors, not Buttons-in-Links: a file download
                            must not be intercepted by Inertia, which waits for an
                            Inertia response the browser will never receive. Styled
                            to match Button variant="outline" size="sm". */}
                        <div className="mt-4 flex items-center justify-center gap-2">
                            {exportFormats.map((fmt) => (
                                <a
                                    key={fmt}
                                    href={route('admin.qr.inventory.download', { code: code.serial_number, format: fmt })}
                                    className="inline-flex items-center justify-center rounded-soft border border-neutral-300 bg-transparent px-3 py-1.5 text-sm font-medium text-neutral-700 transition-all duration-150 hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    <Download className="mr-1.5 h-4 w-4" /> {t(`smart_qr.format_${fmt}`)}
                                </a>
                            ))}
                        </div>
                    </Card>
                </div>
            </div>

            {/* ⚠️ codeIds is an ARRAY — the modal was built for bulk selection and
                needs no variant for one code. */}
            {/* ⚠️ ADAPTED AT THE CALL SITE, not by widening the modal's contract.
                EditAssignmentModal reads `assignment.code?.serial_number` and
                `assignment.workspace?.name` — the Assignments page's row shape.
                This page's currentAssignment prop is flat (workspace_name), so the
                nesting is rebuilt here. Changing the modal to accept both shapes
                would put a second contract inside a component that exists to have
                exactly one. */}
            {canAssign && editing && currentAssignment && (
                <EditAssignmentModal
                    assignment={{
                        ...currentAssignment,
                        code: { serial_number: code.serial_number },
                        workspace: { name: currentAssignment.workspace_name },
                    }}
                    onClose={() => setEditing(false)}
                />
            )}

            {canAssign && assigning && (
                <AssignQrModal
                    show
                    onClose={() => setAssigning(false)}
                    onAssigned={() => setAssigning(false)}
                    codeIds={[code.id]}
                    workspaces={workspaces}
                />
            )}
        </AdminLayout>
    );
}
