import { Head, Link, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Card, Pagination } from '@/Components/ui';
import { ArrowLeft, Layers, Package, QrCode, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CodeStatusBadge, AssignmentStateBadge, BatchStatusBadge } from '../QrStatusBadge';
import { formatDateTz } from '@/Utils/datetime';

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
        <Card className="p-5">
            <div className="flex items-start gap-3">
                <div className="mt-0.5 text-brand-600 dark:text-brand-400"><Icon className="h-5 w-5" /></div>
                <div>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
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

    const adminTz = usePage().props.timezone || 'UTC';

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

                    <div className="flex flex-wrap items-center gap-3">
                        <Layers className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {batch.batch_name}
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
                        <BatchStatusBadge status={batch.status} />
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

                <div className="grid gap-4 sm:grid-cols-3">
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
        </AdminLayout>
    );
}
