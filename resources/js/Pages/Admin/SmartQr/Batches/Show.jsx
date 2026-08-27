import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input, Modal, Pagination } from '@/Components/ui';
import { ArrowLeft, CircleCheck, Layers, Link2, Package, QrCode, Printer, Pencil, TriangleAlert, Download } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CodeStatusBadge, AssignmentStateBadge, BatchStatusBadge } from '../QrStatusBadge';
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
export default function SmartQrBatchShow({ batch, codes }) {
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

                            {/* ⚠️ DELIBERATE PLACEHOLDER — DISABLED, WIRED TO NOTHING.
                                There is no batch-scoped export route: the only export
                                is POST admin.qr.inventory.export, which takes
                                code_ids[] and is capped at GenerateQrExportJob::
                                MAX_CODES (500). A batch can hold up to 10,000 codes,
                                so this needs its own route AND a decision about
                                batches over the cap. Both are a separate slice.

                                It is rendered disabled with a "coming soon" title
                                rather than omitted, by owner decision. Do NOT wire an
                                onClick here without that route existing — a control
                                that silently does nothing is worse than an absent
                                one, which is why this one is visibly inert. */}
                            {/* ⚠️ The title sits on a WRAPPER, not on the button.
                                Button applies `disabled:pointer-events-none`, so a
                                disabled button cannot be hovered — a title attribute
                                on it would be in the DOM and never render a tooltip.
                                The span still receives pointer events, so the
                                "coming soon" hint actually appears, and it is where
                                cursor-not-allowed can show. */}
                            <span
                                title={t('smart_qr.export_zip_coming_soon')}
                                className="inline-flex cursor-not-allowed"
                            >
                                <Button variant="outline" size="sm" disabled>
                                    <Download className="mr-1.5 h-4 w-4" /> {t('smart_qr.export_zip')}
                                </Button>
                            </span>

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
