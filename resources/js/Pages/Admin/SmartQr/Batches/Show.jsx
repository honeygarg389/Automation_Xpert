import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input, Modal, Pagination } from '@/Components/ui';
import { ArrowLeft, CircleCheck, Layers, Link2, Package, QrCode, Printer, Pencil, TriangleAlert, Download } from 'lucide-react';
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
export default function SmartQrBatchShow({ batch, codes, exports = [] }) {
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

    // ⚠️ Disables the button for the round trip only. A 20-part batch dispatches
    // 20 jobs in one request; without this, an impatient second click queues a
    // whole duplicate set of parts before the first response lands.
    const [exporting, setExporting] = useState(false);

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
                            {/* "Status : <badge>" — Card's border tokens, not a Card,
                                because a Card carries padding/shadow meant for a
                                block, not an inline pill. */}
                            <div className="flex items-center gap-2 rounded-soft-lg border border-soft border-neutral-200 px-3 py-1.5 dark:border-neutral-700">
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

                            {/* ⚠️ NOW WIRED — the placeholder's blocker is gone.
                                It was disabled because no batch-scoped route
                                existed and a control that silently does nothing
                                is worse than an absent one. POST
                                admin.qr.batches.export now exists, resolves the
                                code ids server-side and chunks them at
                                MAX_CODES, so the button does what it says.

                                ⚠️ NO FORMAT PICKER, DELIBERATELY. The Inventory
                                export opens a modal to choose one because that
                                screen is where an admin assembles an arbitrary
                                selection and is already deciding things. Here
                                the entry point is a single button on one
                                batch's page, and the server defaults to SVG —
                                the format this module already defaults to
                                everywhere, and the one that prints crisply at
                                any size. Adding a modal for a single click
                                would be a decision imposed where none is
                                needed; a format picker belongs here only if an
                                admin actually asks to export a batch as PNG. */}
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={exporting}
                                onClick={() => {
                                    setExporting(true);
                                    router.post(route('admin.qr.batches.export', batch.uuid), {}, {
                                        preserveScroll: true,
                                        onFinish: () => setExporting(false),
                                    });
                                }}
                            >
                                <Download className="mr-1.5 h-4 w-4" /> {t('smart_qr.export_zip')}
                            </Button>

                            {canManage && (
                                <Button variant="outline" size="sm" onClick={() => setRetiring(batch)}>
                                    <TriangleAlert className="mr-1.5 h-4 w-4" /> {t('smart_qr.retire_batch')}
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

                {/* ═══ ⚠️ EXPORT PARTS — AN INERTIA PROP, NOT A POLLER ══════════
                    A batch over 500 codes exports as N parts, each built by its
                    own queue job, so parts appear one at a time. This panel is
                    populated at page load and refreshed by revisiting — the
                    module's established "fire and forget, check back" pattern,
                    identical to the Inventory page's Ready Exports panel.

                    ⚠️ NO AUTO-REFRESH, DELIBERATELY. Nothing in this module
                    polls (the JSON exports endpoints have no callers in
                    resources/js), and a timer here would make this the only
                    screen that behaves differently — a divergence to maintain
                    forever for a job that finishes in seconds. The success
                    flash already tells the admin parts appear as jobs finish.

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
            </div>

            {canManage && renaming && <RenameBatchModal batch={renaming} onClose={() => setRenaming(null)} />}

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
        </AdminLayout>
    );
}
