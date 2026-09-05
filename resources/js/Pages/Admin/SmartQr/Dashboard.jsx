import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { StatTile, WidgetCard, EmptyState } from '@/Components/Dashboard';
import { DonutChart, LineChart } from '@/Components/Charts';
import { BatchStatusBadge } from './QrStatusBadge';
import { formatDateTz } from '@/Utils/datetime';
import { useTranslation } from 'react-i18next';
import {
    QrCode, Archive, Zap, Layers, Printer, Settings, Ban, Trash2, Plus, Warehouse, Lock,
} from 'lucide-react';

/**
 * §"QR Management > Dashboard" — the sidebar's long-standing "Soon" placeholder.
 *
 * ⚠️ REUSES THE MAIN ADMIN DASHBOARD'S COMPONENTS, not new ones. StatTile and
 * WidgetCard (@/Components/Dashboard) are already generic and already carry
 * this exact shape (icon/label/value cards + titled list panels with a "View
 * all" action) on the platform-wide Dashboard — building parallel components
 * here would be a second version of the same pattern for one more domain.
 *
 * ⚠️ NO DELTA/SPARKLINE on any tile. StatTile supports both, but every number
 * here is a live "what is true right now" count (mirroring the reasoning
 * SmartQrMetrics::overview() already documents for its own three live counts)
 * — there is no historical comparison series for "how many codes are
 * Retired" to chart against.
 */
export default function SmartQrDashboard({
    stats, recentBatches, recentAssignments, nearExpiring, recentlyEnded,
    lockedCodes, topWorkspaces, scanVolume, recentActivity,
}) {
    const { t } = useTranslation();
    const adminTz = usePage().props.timezone || 'UTC';

    // ⚠️ The controller sends 30 days; the toggle slices client-side rather
    // than re-fetching a subset the page already holds.
    const [scanRange, setScanRange] = useState(30);
    const scanSeries = scanRange === 7 ? scanVolume.slice(-7) : scanVolume;

    // ⚠️ The "Other" bucket arrives FLAGGED, not named — see the controller.
    // Workspace names are tenant data; the bucket label is UI copy.
    const donutData = topWorkspaces.map((w) => ({
        name: w.is_other ? t('smart_qr.donut_other') : (w.name ?? '—'),
        value: w.value,
    }));

    const heldLabel = (days) => (days === 0
        ? t('smart_qr.held_under_day')
        : t('smart_qr.held_days', { count: days }));

    const tiles = [
        { icon: QrCode, label: t('smart_qr.stat_total_codes'), value: stats.total_codes },
        { icon: Archive, label: t('smart_qr.stat_available'), value: stats.available },
        { icon: Zap, label: t('smart_qr.stat_active'), value: stats.active },
        { icon: Layers, label: t('smart_qr.stat_total_batches'), value: stats.total_batches },
        { icon: Printer, label: t('smart_qr.stat_printed'), value: stats.printed },
        { icon: Settings, label: t('smart_qr.stat_configured'), value: stats.configured },
        { icon: Ban, label: t('smart_qr.stat_inactive'), value: stats.inactive },
        { icon: Trash2, label: t('smart_qr.stat_retired'), value: stats.retired },
    ];

    return (
        <AdminLayout title={t('smart_qr.dashboard_title')}>
            <Head title={`${t('smart_qr.dashboard_title')} · ${t('head.admin')}`} />

            <div className="space-y-6">
                <div>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('smart_qr.dashboard_title')}
                    </h2>
                    <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                        {t('smart_qr.dashboard_subtitle')}
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {tiles.map((tile) => (
                        <StatTile key={tile.label} {...tile} />
                    ))}
                </div>

                {/* ⚠️ LOCKED CODES RIDES IN THIS ROW RATHER THAN BECOMING A 9th
                    TILE. The grid above is a fixed 8, and locked codes is a
                    different kind of number anyway — an EXCEPTION an admin
                    caused, not a state the inventory arrived in. The quick-
                    actions card was a full-width band holding two small
                    buttons, so this fills real dead space instead of adding a
                    row, and reuses StatTile rather than inventing a header
                    badge that would be this page's only one. */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        {/* ⚠️ Both links land on the existing index pages, not a
                            deep link into either page's own create flow. Batch
                            creation is a modal local to Batches/Index.jsx's own
                            state — there is no route or query param that opens
                            it from elsewhere, so "Create QR Batch" here goes to
                            the same screen the New Batch button already lives
                            on, matching what a "quick link" can honestly
                            promise. */}
                        <WidgetCard title={t('smart_qr.quick_actions')}>
                            <div className="flex flex-wrap gap-3">
                                <a
                                    href={route('admin.qr.batches.index')}
                                    className="inline-flex items-center gap-2 rounded-soft bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                                >
                                    <Plus className="h-4 w-4" /> {t('smart_qr.create_batch')}
                                </a>
                                <a
                                    href={route('admin.qr.inventory.index')}
                                    className="inline-flex items-center gap-2 rounded-soft border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                >
                                    <Warehouse className="h-4 w-4" /> {t('smart_qr.view_inventory')}
                                </a>
                            </div>
                        </WidgetCard>
                    </div>

                    <StatTile icon={Lock} label={t('smart_qr.stat_locked')} value={lockedCodes} />
                </div>

                {/* ⚠️ Charts sit two-up, matching the main Admin Dashboard's own
                    chart rows (lg:grid-cols-2, height 220-240). The donut's
                    outerRadius is 100 — a 200px circle — which needs roughly a
                    half-width card to breathe; a third column would squeeze it
                    below its own radius. */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <WidgetCard title={t('smart_qr.top_workspaces')} subtitle={t('smart_qr.top_workspaces_sub')}>
                        {donutData.length === 0 ? (
                            <EmptyState>{t('smart_qr.no_top_workspaces')}</EmptyState>
                        ) : (
                            <DonutChart data={donutData} nameKey="name" valueKey="value" height={240} />
                        )}
                    </WidgetCard>

                    <WidgetCard
                        title={t('smart_qr.scan_volume')}
                        subtitle={t('smart_qr.scan_volume_sub')}
                        action={
                            /* ⚠️ Slices data already in hand — no second request. */
                            <div className="inline-flex overflow-hidden rounded-soft border border-neutral-300 dark:border-neutral-600">
                                {[7, 30].map((days) => (
                                    <button
                                        key={days}
                                        type="button"
                                        onClick={() => setScanRange(days)}
                                        aria-pressed={scanRange === days}
                                        className={`px-2.5 py-1 text-xs font-medium transition ${
                                            scanRange === days
                                                ? 'bg-brand-600 text-white'
                                                : 'bg-white text-neutral-600 hover:bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                        }`}
                                    >
                                        {days === 7 ? t('smart_qr.range_7d') : t('smart_qr.range_30d')}
                                    </button>
                                ))}
                            </div>
                        }
                    >
                        {scanSeries.every((d) => d.scans === 0) ? (
                            <EmptyState>{t('smart_qr.no_scan_data')}</EmptyState>
                        ) : (
                            <LineChart
                                data={scanSeries}
                                xKey="date"
                                yKeys={['scans', 'unique_scans']}
                                labels={{ scans: t('smart_qr.chart_scans'), unique_scans: t('smart_qr.chart_unique') }}
                                height={240}
                            />
                        )}
                    </WidgetCard>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <WidgetCard
                        title={t('smart_qr.recent_batches')}
                        action={
                            <a href={route('admin.qr.batches.index')} className="text-sm text-brand-600 hover:underline dark:text-brand-400">
                                {t('admin.view_all')}
                            </a>
                        }
                    >
                        {recentBatches.length === 0 ? (
                            <EmptyState>{t('smart_qr.no_batches')}</EmptyState>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_batch')}</th>
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.stat_quantity')}</th>
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_status')}</th>
                                            <th className="pb-2 text-right font-medium">{t('admin.col_created')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                        {recentBatches.map((b) => (
                                            <tr key={b.uuid}>
                                                <td className="py-2 pr-4">
                                                    <p className="font-medium text-neutral-800 dark:text-neutral-200">{b.batch_name}</p>
                                                    <p className="font-mono text-xs text-neutral-400">{b.batch_number}</p>
                                                </td>
                                                <td className="py-2 pr-4 text-neutral-600 dark:text-neutral-400">{b.quantity}</td>
                                                <td className="py-2 pr-4"><BatchStatusBadge status={b.status} /></td>
                                                <td className="py-2 text-right text-neutral-500">{formatDateTz(b.created_at, adminTz)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </WidgetCard>

                    <WidgetCard
                        title={t('smart_qr.recently_assigned')}
                        action={
                            <a href={route('admin.qr.assignments.index')} className="text-sm text-brand-600 hover:underline dark:text-brand-400">
                                {t('admin.view_all')}
                            </a>
                        }
                    >
                        {recentAssignments.length === 0 ? (
                            <EmptyState>{t('smart_qr.no_assignments')}</EmptyState>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_serial')}</th>
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_workspace')}</th>
                                            <th className="pb-2 text-right font-medium">{t('smart_qr.col_assigned_at')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                        {recentAssignments.map((a) => (
                                            <tr key={a.uuid}>
                                                <td className="py-2 pr-4 font-mono text-neutral-800 dark:text-neutral-200">{a.serial_number ?? '—'}</td>
                                                <td className="py-2 pr-4 text-neutral-600 dark:text-neutral-400">{a.workspace_name ?? '—'}</td>
                                                <td className="py-2 text-right text-neutral-500">{formatDateTz(a.assigned_at, adminTz)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </WidgetCard>
                </div>

                {/* ⚠️ PAIRED TWO-UP, unlike Recent Batches which needed a full
                    row. Both panels here carry THREE content columns, and the
                    arithmetic is what separates them: at the lg breakpoint
                    (≈710px of main content beside the sidebar, less one 16px
                    gap) a half-width card is ≈347px, ≈315px inside its padding.
                    Three columns — a mono serial (~90px), a workspace name
                    (~110px) and a date (~85px) — come to ≈285px and fit.
                    Recent Batches could not: its FOURTH column (a status badge
                    at ~75px on top of the same date) pushed it past the same
                    budget, which is why that one still owns a full row.

                    ⚠️ Recently Ended's duration rides UNDER its date rather
                    than taking a fourth column, for that reason — the same
                    stacking Recent Batches uses for name-over-number.

                    ⚠️ NEITHER TAKES A "View all" ACTION, deliberately: the
                    assignments index has no expiry filter and no ended filter,
                    so either link would land on a list that does not contain
                    the set shown. Recent Activity omits its action for the same
                    reason — there is no page to point at. */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <WidgetCard title={t('smart_qr.near_expire')}>
                        {nearExpiring.length === 0 ? (
                            <EmptyState>{t('smart_qr.no_near_expire')}</EmptyState>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_serial')}</th>
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_workspace')}</th>
                                            <th className="pb-2 text-right font-medium">{t('smart_qr.col_expires')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                        {nearExpiring.map((a) => (
                                            <tr key={a.uuid}>
                                                <td className="py-2 pr-4 font-mono text-neutral-800 dark:text-neutral-200">{a.serial_number ?? '—'}</td>
                                                <td className="py-2 pr-4 text-neutral-600 dark:text-neutral-400">{a.workspace_name ?? '—'}</td>
                                                <td className="py-2 text-right text-neutral-500">{formatDateTz(a.expires_at, adminTz)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </WidgetCard>

                    <WidgetCard title={t('smart_qr.recently_ended')}>
                        {recentlyEnded.length === 0 ? (
                            <EmptyState>{t('smart_qr.no_recently_ended')}</EmptyState>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_serial')}</th>
                                            <th className="pb-2 pr-4 font-medium">{t('smart_qr.col_workspace')}</th>
                                            <th className="pb-2 text-right font-medium">{t('smart_qr.col_ended_at')}</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                                        {/* ⚠️ THE SAME SERIAL LEGITIMATELY REPEATS.
                                            A code is reassigned by closing one period
                                            and opening another, so two ended rows for
                                            one code are two different tenancies, not a
                                            rendering fault. The workspace column is
                                            what distinguishes them, so it is NOT
                                            muted to the same weight as the date — it
                                            carries the same emphasis as the serial. */}
                                        {recentlyEnded.map((a) => (
                                            <tr key={a.uuid}>
                                                <td className="py-2 pr-4 font-mono text-neutral-800 dark:text-neutral-200">{a.serial_number ?? '—'}</td>
                                                <td className="py-2 pr-4 text-neutral-700 dark:text-neutral-300">{a.workspace_name ?? '—'}</td>
                                                <td className="py-2 text-right">
                                                    <span className="text-neutral-500">{formatDateTz(a.unassigned_at, adminTz)}</span>
                                                    {a.held_days !== null && (
                                                        <span className="block text-xs text-neutral-400">{heldLabel(a.held_days)}</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </WidgetCard>
                </div>

                {/* ⚠️ Raw `action` strings, not humanized prose — matching the
                    one other audit-log viewer in this admin app
                    (Integrations/AuditLog.jsx), which renders `log.action`
                    plainly in a pill rather than composing a sentence per
                    action type. Inventing per-action prose here (14 distinct
                    smart_qr.* strings today, more later) would be a second,
                    divergent formatting rule for the same data. */}
                <WidgetCard title={t('smart_qr.recent_activity')}>
                    {recentActivity.length === 0 ? (
                        <EmptyState>{t('smart_qr.no_recent_activity')}</EmptyState>
                    ) : (
                        <ul className="divide-y divide-neutral-100 dark:divide-neutral-700/50">
                            {recentActivity.map((entry) => (
                                <li key={entry.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                    <div className="flex items-center gap-2 min-w-0">
                                        <span className="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                            {entry.action}
                                        </span>
                                        <span className="truncate text-neutral-500 dark:text-neutral-400">
                                            {entry.actor_name ?? t('common.system')}
                                        </span>
                                    </div>
                                    <span className="shrink-0 text-xs text-neutral-400">
                                        {formatDateTz(entry.created_at, adminTz)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </WidgetCard>
            </div>
        </AdminLayout>
    );
}
