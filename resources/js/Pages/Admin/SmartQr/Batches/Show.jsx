import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, ConfirmDestructiveModal, Dropdown, Input, Modal, Pagination } from '@/Components/ui';
import { ArrowLeft, CircleCheck, Layers, Link2, Package, QrCode, Printer, Pencil, Plus, TriangleAlert, Download, ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CodeStatusBadge, AssignmentStateBadge, BatchStatusBadge, ExportStatusBadge } from '../QrStatusBadge';
import { formatDateTz } from '@/Utils/datetime';

/**
 * Rename only — see UpdateQrBatchRequest for why the serial range is absent.
 *
 * ⚠️ MOVED here from the batch LIST row. Identical component, identical route
 * and params — only the trigger's location changed.
 */
function RenameBatchModal({ batch, onClose }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm({
        batch_name: batch?.batch_name ?? '',
        batch_number: batch?.batch_number ?? '',
    });

    if (! batch) return null;

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <Modal.Header title={t('smart_qr.rename_batch')} onClose={onClose} />
            <form onSubmit={(e) => { e.preventDefault(); patch(route('admin.qr.batches.update', batch.uuid), { onSuccess: onClose }); }}>
                <Modal.Body className="space-y-4">
                    <Input
                        label={t('smart_qr.field_batch_name')}
                        value={data.batch_name}
                        onChange={(e) => setData('batch_name', e.target.value)}
                        error={errors.batch_name}
                        required
                    />
                    {/* ⚠️ Editable — checked, not assumed. Nothing looks a batch
                        up by this value: the route key is uuid, serials come from
                        `prefix`, and the range rule matches on prefix too. It is
                        an operator-facing label, kept unique. */}
                    <Input
                        label={t('smart_qr.field_batch_number')}
                        value={data.batch_number}
                        onChange={(e) => setData('batch_number', e.target.value)}
                        error={errors.batch_number}
                        required
                    />
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" disabled={processing}>{t('smart_qr.save')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

/**
 * Neutral confirm for retiring a batch.
 *
 * ⚠️ MOVED here from the batch LIST row, unchanged.
 *
 * ⚠️ NOT ConfirmDestructiveModal, deliberately. That component is documented as
 * "Typed confirmation for an irreversible action" and renders a red button
 * behind a type-the-word gate — correct for delete, wrong signal for retire.
 * Retiring KEEPS every row: the codes stay in inventory, stop being assignable,
 * and a scan reports the QR inactive. Framing a reversible state change in the
 * same red as an unrecoverable delete teaches operators to click through both.
 *
 * Structure copied from DeleteConfirmModal in Admin/Plans/Index.jsx — the
 * existing click-to-confirm precedent — minus its red button override.
 */
function RetireConfirmModal({ show, batch, onClose, onConfirm }) {
    const { t } = useTranslation();
    if (! batch) return null;
    return (
        <Modal show={show} onClose={onClose} maxWidth="sm">
            <Modal.Header title={t('smart_qr.retire_batch')} onClose={onClose} />
            <Modal.Body>
                <p className="text-neutral-600 dark:text-neutral-400">
                    {t('smart_qr.retire_batch_body')}
                </p>
                <p className="mt-2 font-medium text-neutral-900 dark:text-neutral-100">
                    {batch.batch_name}
                </p>
                <p className="mt-0.5 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                    {batch.batch_number}
                </p>
            </Modal.Body>
            <Modal.Footer>
                <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                <Button variant="primary" onClick={() => onConfirm(batch)}>
                    {t('smart_qr.retire_batch')}
                </Button>
            </Modal.Footer>
        </Modal>
    );
}

/**
 * Stat pill.
 *
 * ⚠️ Follows the page-local StatCard in Admin/AI/Dashboard.jsx and
 * Admin/Support/Index.jsx — Card + icon + neutral tokens — NOT Charts/KpiCard.
 *
 * KpiCard was the obvious candidate and is the wrong one here, measured: it is
 * used only on CLIENT pages, it speaks `gray-*` and `rounded-xl` where every
 * admin surface speaks `neutral-*` and `rounded-soft-lg`, and it imports recharts
 * at module scope — which would pull a charting library into a 4 KB page that
 * draws no chart.
 */
function StatCard({ icon: Icon, label, value }) {
    return (
        <Card className="p-4">
            <div className="flex items-start gap-2">
                <div className="mt-0.5 text-brand-600 dark:text-brand-400"><Icon className="h-5 w-5" /></div>
                <div>
                    <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">{label}</p>
                    <p className="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{value}</p>
                </div>
            </div>
        </Card>
    );
}

/**
 * §4 — one batch and the codes it generated.
 *
 * ⚠️ Read-only. Every mutation lives on the inventory screen, which already has
 * the selection model and the bulk actions — duplicating them here would be two
 * places to change when a bulk action gains an option.
 */
/**
 * "more QR's" — extend an existing batch.
 *
 * ⚠️ MIRRORS RenameBatchModal ABOVE: same Modal/Modal.Header/useForm shape, same
 * processing-disabled submit. Two modals on one screen that handle a form
 * differently is how they drift.
 *
 * ⚠️ THE RANGE PREVIEW IS THE POINT OF THIS MODAL. "How many more?" is
 * answerable without help; "which serials am I about to commit to?" is not, and
 * it is the thing that gets printed. Computed client-side from the same formula
 * the server uses (serial_start + offset, zero-padded to 6) — see
 * GenerateQrBatchAction::serialFor.
 *
 * ⚠️ Padding is `padStart(6, '0')` and NOT a fixed-width assumption: %06d does
 * not truncate, so a batch crossing 999999 renders 7 digits on both sides. A
 * preview that silently disagreed with the printed serial would be worse than
 * none.
 */
function AddCodesModal({ batch, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({ additional_quantity: '' });

    const serial = (offset) =>
        `${batch.prefix}-${String(batch.serial_start + offset).padStart(6, '0')}`;

    const parsed = Number.parseInt(data.additional_quantity, 10);
    const valid = Number.isInteger(parsed) && parsed > 0;

    // The new codes continue past the batch's current end, which is
    // serial_start + quantity - 1 — so the first new offset is `quantity`.
    const firstNew = serial(batch.quantity);
    const lastNew = valid ? serial(batch.quantity + parsed - 1) : null;

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.qr.batches.add-codes', batch.uuid), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <Modal.Header title={t('smart_qr.add_codes')} subtitle={batch.batch_number} onClose={onClose} />
            <form onSubmit={submit}>
                <Modal.Body className="space-y-4">
                    <Input
                        type="number"
                        min="1"
                        name="additional_quantity"
                        label={t('smart_qr.add_codes_quantity')}
                        value={data.additional_quantity}
                        onChange={(e) => setData('additional_quantity', e.target.value)}
                        error={errors.additional_quantity}
                        autoFocus
                    />

                    {/* ⚠️ Rendered only for a valid number. An "AX-000011 – NaN"
                        preview while the field is empty or mid-edit reads as a
                        bug in the batch, not as an incomplete form. */}
                    {valid && (
                        <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                            {t('smart_qr.add_codes_preview', { first: firstNew, last: lastNew })}
                        </p>
                    )}

                    {errors.serial_start && (
                        <p className="mt-3 text-sm text-red-500 dark:text-red-400">{errors.serial_start}</p>
                    )}
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={processing || ! valid}>
                        {t('smart_qr.add_codes')}
                    </Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

/**
 * ═══ AUTO-REFRESH WHILE WORK IS IN FLIGHT ═══════════════════════════════════
 *
 * Batch generation and each export part run as queue jobs, so the interesting
 * numbers on this page (generated_count, each part's status) change AFTER the
 * response that rendered it. Without this the admin watches a static page and
 * reloads by hand to find out whether anything happened.
 *
 * ⚠️ ALLOW-LIST OF LIVE STATES, NEVER A DENY-LIST OF TERMINAL ONES. Batches
 * have SIX statuses — draft, generating, generated, failed, printed, AND
 * retired. A
 * guard written as "stop once status is generated or failed" polls a printed
 * batch forever, because `printed` is in neither set. Asking "is it still
 * working?" cannot be wrong when a seventh status is added; asking "is it done
 * yet?" silently can.
 */
const LIVE_BATCH_STATUSES = ['draft', 'generating'];

/**
 * ⚠️ MUST TRACK QrBatchController::addCodes's gate. `draft`/`generating` mean a
 * job is already in flight — raising quantity underneath it races the running
 * generator. `printed` has no writer in the application today, so allowing it
 * would ship a branch nothing can reach.
 */
const EXTENDABLE_BATCH_STATUSES = ['generated', 'failed'];

/** SmartQrExport::STATUS_QUEUED / STATUS_PROCESSING. */
const LIVE_EXPORT_STATUSES = ['queued', 'processing'];

const POLL_INTERVAL_MS = 5000;

/**
 * ⚠️ A CAP, BECAUSE "STILL WORKING" AND "NOTHING IS CONSUMING THE QUEUE" LOOK
 * IDENTICAL FROM HERE.
 *
 * A row stuck at `generating` because no worker is running is indistinguishable
 * in the props from one that is genuinely mid-flight — this codebase has the
 * measured incident on record: a `queue:listen` up and visible in `ps` that had
 * consumed nothing for three days, with every dashboard looking clean. Polling
 * on that forever is the page quietly lying that something is happening.
 *
 * 60 attempts x 5s = 5 minutes, after which the page says so and stops asking.
 */
const MAX_POLL_ATTEMPTS = 60;

export default function SmartQrBatchShow({ batch, codes, exports = [], forceDeleteAvailable = false }) {
    const { t } = useTranslation();
    const flash = usePage().props.flash || {};

    const rows = codes?.data ?? [];

    const page = usePage();
    const adminTz = page.props.timezone || 'UTC';

    /**
     * ⚠️ manage_qr_batches — READ FROM routes/admin.php, not assumed.
     * Both `batches.update` and `batches.retire` carry
     * `permission:manage_qr_batches`. (`view_qr_inventory` gates index/show, and
     * gating these on it would offer a control the server refuses.)
     */
    const canManage = (page.props.auth?.permissions ?? []).includes('manage_qr_batches');

    const [renaming, setRenaming] = useState(null);
    const [retiring, setRetiring] = useState(null);
    const [forceDeleting, setForceDeleting] = useState(false);
    const [addingCodes, setAddingCodes] = useState(false);

    // ⚠️ Disables the button for the round trip only. A 20-part batch dispatches
    // 20 jobs in one request; without this, an impatient second click queues a
    // whole duplicate set of parts before the first response lands.
    const [exporting, setExporting] = useState(false);

    /**
     * Liveness, derived from the props themselves rather than tracked in state
     * — the server's answer is the only authority on whether work is still
     * running, and a local copy could disagree with it.
     */
    const batchIsLive = LIVE_BATCH_STATUSES.includes(batch.status);
    const liveExportCount = exports.filter((e) => LIVE_EXPORT_STATUSES.includes(e.status)).length;
    const isLive = batchIsLive || liveExportCount > 0;

    /**
     * ⚠️ EXHAUSTION IS KEYED TO WHAT WAS BEING WAITED ON, not a bare boolean.
     *
     * A bare flag needs an effect to reset it when the work changes, and
     * setState inside an effect body is both a lint error here and a real
     * cascading-render hazard. Storing WHICH liveness state gave up makes the
     * reset fall out of the comparison for free: the moment the batch status or
     * the live-part count changes, this key stops matching and polling resumes
     * on its own.
     */
    const livenessKey = `${batch.status}:${liveExportCount}`;
    const [exhaustedFor, setExhaustedFor] = useState(null);
    const pollExhausted = exhaustedFor === livenessKey;

    /**
     * ⚠️ A REF, NOT STATE. Counting in state re-renders every tick and re-runs
     * the effect on its own counter — tearing the interval down and rebuilding
     * it 60 times, which resets the 5s clock each time so the cap never lands.
     */
    const attemptsRef = useRef(0);

    /**
     * Follows Broadcasting/Campaigns/Show.jsx exactly — guard clause, interval,
     * partial reload, clearInterval on unmount, keyed on the liveness value.
     * That screen polls a queued campaign this way; this is a new caller of an
     * existing pattern, not a new pattern.
     *
     * ⚠️ 5s WHERE CAMPAIGNS USES 8-10s, DELIBERATELY. Those screens watch sends
     * running for minutes, so 10s of staleness is invisible. Export parts are
     * measured at ~130ms each — at 8s a finished part reads as pending for most
     * of its real wait, which is the entire thing this poll exists to show.
     *
     * ⚠️ The three props are exactly what show() renders, and none is lazy — so
     * `only` trims the PAYLOAD, not the server's work.
     */
    useEffect(() => {
        if (!isLive || pollExhausted) return;

        attemptsRef.current = 0;

        const id = setInterval(() => {
            attemptsRef.current += 1;

            if (attemptsRef.current > MAX_POLL_ATTEMPTS) {
                setExhaustedFor(livenessKey);

                return;
            }

            router.reload({
                only: ['batch', 'codes', 'exports'],
                preserveScroll: true,
                preserveState: true,
            });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(id);
    }, [isLive, pollExhausted, livenessKey]);

    /**
     * ⚠️ THREE PILLS, not the reference's five.
     *
     * "Assigned" and "Active" are NOT in this page's props: show() returns
     * `batch` and a paginated `codes`, with no withCount. Deriving them from
     * `rows` would count only the CURRENT PAGE of 50 — a number that looks
     * plausible on a small batch and under-reports every large one. They need a
     * controller change, which is a separate slice.
     */
    const stats = [
        { icon: Package, label: t('smart_qr.stat_quantity'), value: batch.quantity },
        { icon: QrCode, label: t('smart_qr.stat_generated'), value: batch.generated_count },
        { icon: Printer, label: t('smart_qr.stat_printed'), value: batch.printed_count },
        // ⚠️ assigned_count / active_count come from QrBatchController::show()'s
        // loadCount() — derived (R-12), never a stored column. `Link2` is
        // reused from Inventory/Index.jsx's "Bulk Assign" action rather than a
        // new icon; `CircleCheck` matches AssignmentStateBadge's `success`
        // (green) mapping for the same 'active' status elsewhere in this
        // module, so the pill and the badge agree on what "active" looks like.
        { icon: Link2, label: t('smart_qr.stat_assigned'), value: batch.assigned_count },
        { icon: CircleCheck, label: t('smart_qr.stat_active'), value: batch.active_count },
    ];

    return (
        <AdminLayout title={batch.batch_number}>
            <Head title={`${batch.batch_number} · ${t('head.admin')}`} />

            <div className="space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 px-4 py-2 text-sm text-green-800 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                {/* ⚠️ ONLY AFTER THE CAP — not whenever something is in flight.
                    Shown while the page still believes work is live but has
                    stopped asking, which is the one state the admin cannot infer
                    from anything else on screen: the status pills still read
                    "generating" and nothing moves. Says the page stopped, not
                    that the job failed — the page does not know which. */}
                {pollExhausted && isLive && (
                    <div
                        role="status"
                        className="rounded-soft-lg bg-amber-50 dark:bg-amber-900/30 px-4 py-2 text-sm text-amber-800 dark:text-amber-200"
                    >
                        {t('smart_qr.poll_stalled')}
                    </div>
                )}

                <div>
                    <Link
                        href={route('admin.qr.batches.index')}
                        className="mb-3 inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        <ArrowLeft className="h-4 w-4" /> {t('smart_qr.back_to_batches')}
                    </Link>

                    {/* Header: identity on the LEFT, actions on the RIGHT.
                        justify-between splits the two groups; flex-wrap keeps the
                        action group intact and drops it below on narrow widths
                        rather than letting Retire and Status separate. */}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <Layers className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                            <div>
                                <h2 className="flex items-center gap-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                    {batch.batch_name}
                                    {canManage && (
                                        <button
                                            type="button"
                                            onClick={() => setRenaming(batch)}
                                            aria-label={t('smart_qr.rename_batch')}
                                            className="p-1 text-neutral-400 transition hover:text-brand-600"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </button>
                                    )}
                                </h2>
                                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-neutral-500 dark:text-neutral-400">
                                    <span className="font-mono">{batch.batch_number}</span>
                                    {batch.created_at && (
                                        <>
                                            <span aria-hidden="true">·</span>
                                            <span>{formatDateTz(batch.created_at, adminTz)}</span>
                                        </>
                                    )}
                                </p>
                            </div>
                        </div>

                        {/* Right-hand action group: Status, Export ZIP, Retire.
                            One flex container so the three stay adjacent and move
                            together when the header wraps. */}
                        <div className="flex flex-wrap items-center gap-3">
                            {/* ⚠️ HIDDEN when not applicable, not disabled — matching
                                the Retire button below, which is also `canManage &&`.
                                A disabled control invites "why can't I?"; on a
                                generating batch the answer is "wait a moment", which
                                the Status pill beside it already says. */}
                            {canManage && EXTENDABLE_BATCH_STATUSES.includes(batch.status) && (
                                <Button variant="outline" size="sm" onClick={() => setAddingCodes(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" /> {t('smart_qr.add_codes')}
                                </Button>
                            )}

                            {/* "Status : <badge>" — sized to sit flush with the
                                Button size="sm" controls on either side.

                                ⚠️ EVERY VALUE HERE WAS MEASURED, NOT GUESSED, and
                                three of the four differed from the buttons:

                                  py-1.5   -> py-px            44px tall -> 34px
                                  rounded-soft-lg -> rounded-soft   12px -> 8px
                                  border-neutral-200 + border-soft -> neutral-300
                                              rgba(228,228,231,.6) -> rgb(212,212,216)

                                py-px looks like a typo and is not: this div wraps a
                                Badge that brings its OWN border and py-1, so the
                                outer padding that makes a button 34px tall makes
                                this 44px. 1px is what is left once the Badge's box
                                is accounted for, and it lands the height and the top
                                edge exactly on the buttons'.

                                ⚠️ border-soft is DROPPED, not replaced. It applies
                                0.6 alpha, which is why the pill read as a lighter
                                outline than the buttons beside it even when the
                                colour token matched. */}
                            <div className="flex items-center gap-2 rounded-soft border border-neutral-300 px-3 py-px dark:border-neutral-600">
                                {/* text-sm to match Button size="sm" (px-3 py-1.5
                                    text-sm) — what Export ZIP and Retire render at.
                                    Reuses that class, no new size introduced. */}
                                <span className="text-sm font-medium text-neutral-500 dark:text-neutral-400">
                                    {t('smart_qr.col_status')} :
                                </span>
                                {/* size="md" -> text-sm, matching the Export ZIP and
                                    Retire buttons beside it. The list keeps 'sm'. */}
                                <BatchStatusBadge status={batch.status} size="md" />
                            </div>

                            {/* ⚠️ A FORMAT DROPDOWN, REUSING THE SHARED Dropdown
                                PRIMITIVE — not a new control.

                                The previous single-click version defaulted to
                                SVG and deliberately offered no choice, on the
                                argument that a modal for one click imposes a
                                decision. A dropdown is the middle ground the
                                owner asked for: the choice is one extra click
                                and costs nothing when SVG is what you wanted.

                                ⚠️ LABELS COME FROM THE EXISTING format_* KEYS,
                                the same ones client/SmartQr/Codes.jsx uses for
                                its per-code download — so "PDF (print)" reads
                                identically on both screens. Inventory's
                                export_format_* keys are the long descriptive
                                variants ("SVG — vector, recommended for
                                print"), which suit a modal with room but not a
                                dropdown item.

                                ⚠️ The in-flight guard is on the TRIGGER and
                                applies to every option, not just the default:
                                each item sets `exporting` before dispatching,
                                and the trigger is disabled while it is set. A
                                batch of 10,000 dispatches 20 jobs per request,
                                so a double-click is 40 jobs and 40 rows. */}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <Button variant="outline" size="sm" disabled={exporting}>
                                        <Download className="mr-1.5 h-4 w-4" /> {t('smart_qr.export_zip')}
                                    </Button>
                                </Dropdown.Trigger>
                                <Dropdown.Content width="56">
                                    {['svg', 'png', 'pdf'].map((fmt) => (
                                        <Dropdown.Item
                                            key={fmt}
                                            disabled={exporting}
                                            onClick={() => {
                                                setExporting(true);
                                                router.post(
                                                    route('admin.qr.batches.export', batch.uuid),
                                                    { format: fmt },
                                                    { preserveScroll: true, onFinish: () => setExporting(false) },
                                                );
                                            }}
                                        >
                                            {t(`smart_qr.format_${fmt}`)}
                                        </Dropdown.Item>
                                    ))}
                                </Dropdown.Content>
                            </Dropdown>

                            {canManage && batch.status !== 'retired' && (
                                <Button variant="outline" size="sm" onClick={() => setRetiring(batch)}>
                                    <TriangleAlert className="mr-1.5 h-4 w-4" /> {t('smart_qr.retire_batch')}
                                </Button>
                            )}

                            {/* ⚠️ Reads as a warning, not as another action in the
                                row. Retire beside it is the SAFE path this one
                                deliberately bypasses, so the two must not look
                                interchangeable. */}
                            {canManage && forceDeleteAvailable && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setForceDeleting(true)}
                                    className="border-red-300 text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-900/20"
                                >
                                    <ShieldAlert className="mr-1.5 h-4 w-4" />
                                    {t('smart_qr.force_delete')}
                                    <span className="ml-1.5 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                        {t('smart_qr.force_delete_dev_only')}
                                    </span>
                                </Button>
                            )}
                        </div>
                    </div>
                </div>

                {/* ⚠️ The failure reason, surfaced rather than buried.
                    Slice 2 went to some trouble to write it OUTSIDE the rolled-back
                    transaction precisely so an operator could read it — a batch
                    stuck at `generating` with no explanation was the failure mode
                    that motivated the column. Hiding it here would waste that. */}
                {batch.failure_reason && (
                    <Card className="border-red-200 dark:border-red-800 bg-red-50/60 dark:bg-red-900/20">
                        <h3 className="text-sm font-semibold text-red-800 dark:text-red-200">
                            {t('smart_qr.generation_failed')}
                        </h3>
                        <p className="mt-1 font-mono text-xs text-red-700 dark:text-red-300">{batch.failure_reason}</p>
                    </Card>
                )}

                <div className="grid gap-3 sm:grid-cols-5">
                    {stats.map((s) => (
                        <StatCard key={s.label} icon={s.icon} label={s.label} value={s.value} />
                    ))}
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_assignment')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_qr_name')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((code) => (
                                    <tr key={code.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="py-3 pr-4 font-mono font-medium text-neutral-900 dark:text-neutral-100">
                                            {code.serial_number}
                                        </td>
                                        <td className="py-3 pr-4"><CodeStatusBadge status={code.status} /></td>
                                        <td className="py-3 pr-4">
                                            <AssignmentStateBadge currentAssignment={code.current_assignment} />
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {code.current_assignment?.name ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.no_codes_yet')}
                        </div>
                    )}

                    <Pagination data={codes} />
                </Card>

                {/* ═══ ⚠️ EXPORT PARTS — NOW AUTO-REFRESHED ═════════════════════
                    A batch over 500 codes exports as N parts, each built by its
                    own queue job, so parts appear one at a time.

                    ⚠️ THIS PANEL USED TO CARRY A "NO AUTO-REFRESH, DELIBERATELY"
                    NOTE. Its argument was that nothing in the app polled, so a
                    timer here would be a lone divergence — and that premise was
                    simply wrong. Broadcasting/Campaigns/Show.jsx and
                    Campaigns/Index.jsx have both polled queued work with
                    setInterval + router.reload({ only }) since before this
                    module existed. The poll above follows that precedent rather
                    than inventing anything, so there is no divergence to
                    maintain — and the old note is recorded here rather than
                    deleted so the next reader does not re-derive it from the
                    same wrong premise.

                    ⚠️ It DOES diverge on interval — 5s against Campaigns' 8-10s
                    — because export parts finish in ~130ms where a campaign send
                    runs for minutes. See the effect for the measurement.

                    ⚠️ Rendered only when parts exist: an empty panel on every
                    batch that has never been exported is noise. */}
                {exports.length > 0 && (
                    <Card>
                        <h3 className="mb-3 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                            {t('smart_qr.exports_panel')}
                        </h3>
                        <ul className="divide-y divide-neutral-100 dark:divide-neutral-700">
                            {exports.map((x) => (
                                <li key={x.id} className="flex items-center justify-between gap-4 py-2 text-sm">
                                    <span className="flex items-center gap-3">
                                        <span className="font-mono text-neutral-700 dark:text-neutral-200">
                                            {t('smart_qr.export_part', { part: x.part_number, total: x.total_parts })}
                                        </span>
                                        <span className="uppercase text-neutral-400 dark:text-neutral-500">{x.format}</span>
                                        <ExportStatusBadge status={x.status} />
                                    </span>

                                    <span className="flex items-center gap-4">
                                        {/* ⚠️ The reason, surfaced where the admin
                                            is — not only in the log. A part that
                                            failed silently is the stuck-job-with-
                                            no-explanation shape this table's
                                            `error` column exists to prevent. */}
                                        {x.status === 'failed' && x.error && (
                                            <span className="text-red-600 dark:text-red-400">
                                                {t('smart_qr.export_failed_reason', { reason: x.error })}
                                            </span>
                                        )}

                                        {/* ⚠️ ONLY a ready part is downloadable, and
                                            the server enforces the same rule — this
                                            hides a link that would 404, it does not
                                            replace the check. */}
                                        {x.status === 'ready' && x.path && (
                                            <a
                                                href={route('admin.qr.batches.export-download', [batch.uuid, x.id])}
                                                className="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:text-brand-700"
                                            >
                                                <Download className="h-4 w-4" /> {t('smart_qr.download_label')}
                                            </a>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

            </div>

            {canManage && renaming && <RenameBatchModal batch={renaming} onClose={() => setRenaming(null)} />}
            {canManage && addingCodes && <AddCodesModal batch={batch} onClose={() => setAddingCodes(false)} />}

            {canManage && (
                <RetireConfirmModal
                    show={!! retiring}
                    batch={retiring}
                    onClose={() => setRetiring(null)}
                    onConfirm={(b) => {
                        router.post(route('admin.qr.batches.retire', b.uuid), {}, { preserveScroll: true });
                        setRetiring(null);
                    }}
                />
            )}

            {canManage && forceDeleteAvailable && (
                <ConfirmDestructiveModal
                    show={forceDeleting}
                    onClose={() => setForceDeleting(false)}
                    title={t('smart_qr.force_delete_title')}
                    body={t('smart_qr.force_delete_body')}
                    confirmWord={t('smart_qr.force_delete_confirm_word')}
                    confirmLabel={t('smart_qr.force_delete_confirm_label')}
                    onConfirm={() => {
                        router.delete(route('admin.qr.batches.forceDestroy', batch.uuid), { preserveScroll: true });
                        setForceDeleting(false);
                    }}
                />
            )}
        </AdminLayout>
    );
}
