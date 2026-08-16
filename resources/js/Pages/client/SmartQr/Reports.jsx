import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Select } from '@/Components/ui';
import { KpiCard, LineChart, BarChart, FunnelChart } from '@/Components/Charts';
import { BarChart3, Download } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * §12 — Smart QR Reports. Seven sections, eight visuals, seven filters.
 *
 * ⚠️ NOT a second Overview. §12 says "do not duplicate the same content in both
 * Overview and Reports" and then lists KPI cards under both — a contradiction in
 * six lines. Overview (slice 6) is the operational snapshot: what is assigned
 * right now, recent activity. This is the same measures over TIME, filtered and
 * exportable. Same metrics, genuinely different content.
 *
 * ⚠️ R-19 BINDS EVERY LABEL HERE. Attributed counts UNDER-REPORT — a customer
 * can delete the reference from their own WhatsApp message before sending, and
 * there is deliberately no fallback that guesses (R-20). So nothing on this page
 * says "Customers Messaged"; everything says "Attributed", and the note below
 * the KPIs says why in plain words.
 *
 * ⚠️ No new chart library. recharts and Components/Charts were already here,
 * which is what §12 asks for.
 */
export default function SmartQrReports({ report, filters = {}, filterOptions = {} }) {
    const { t } = useTranslation();
    const [form, setForm] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
        assignment_id: filters.assignment_id ?? '',
        serial: filters.serial ?? '',
        qr_type: filters.qr_type ?? '',
        assigned_user_id: filters.assigned_user_id ?? '',
        status: filters.status ?? '',
        channel_account_id: filters.channel_account_id ?? '',
    });

    const apply = (e) => {
        e?.preventDefault();
        const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v != null));
        router.get(route('client.reports.smartqr.index'), params, { preserveState: true });
    };

    const reset = () => {
        setForm({ from: '', to: '', assignment_id: '', serial: '', qr_type: '', assigned_user_id: '', status: '', channel_account_id: '' });
        router.get(route('client.reports.smartqr.index'), {}, { preserveState: true });
    };

    const totals = report?.totals ?? {};
    const perQr = report?.perQr ?? [];
    const perUser = report?.perUser ?? [];

    const funnelData = (report?.funnel ?? []).map((s) => ({
        name: t(`smart_qr.funnel_${s.name}`, s.name),
        value: s.value,
    }));

    // ⚠️ Encoded by hand rather than with URLSearchParams, which is not in this
    // project's eslint globals — the same gap slice 3b hit on the assignments
    // page. Declaring the global would widen the browser surface the linter
    // accepts across the whole codebase for one query string.
    const exportQuery = Object.entries(form)
        .filter(([, v]) => v !== '' && v != null)
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
        .join('&');

    const exportHref = route('client.reports.smartqr.export') + (exportQuery ? `?${exportQuery}` : '');

    return (
        <ClientLayout title={t('smart_qr.reports_title')}>
            <Head title={t('smart_qr.reports_title')} />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <BarChart3 className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.reports_title')}</h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('smart_qr.reports_subtitle')} · {report?.range?.from} → {report?.range?.to}
                            </p>
                        </div>
                    </div>

                    {/* ⚠️ R-26 — the button says what it exports. A raw export
                        would stop silently at the retention boundary and the
                        file would look complete. */}
                    <div className="text-right">
                        <a
                            href={exportHref}
                            className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                        >
                            <Download className="h-4 w-4" /> {t('smart_qr.export_csv')}
                        </a>
                        <p className="mt-1 max-w-xs text-xs text-neutral-400 dark:text-neutral-500">{t('smart_qr.export_note')}</p>
                    </div>
                </div>

                {/* ── §12 filters (seven) ────────────────────────────────── */}
                <Card>
                    <form onSubmit={apply} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Input type="date" label={t('smart_qr.filter_from')} value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                        <Input type="date" label={t('smart_qr.filter_to')} value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                        <Select
                            label={t('smart_qr.filter_qr')}
                            value={form.assignment_id}
                            onChange={(e) => setForm({ ...form, assignment_id: e.target.value })}
                            options={(filterOptions.qrCodes ?? []).map((q) => ({ value: q.id, label: q.label }))}
                        />
                        <Input label={t('smart_qr.filter_serial')} value={form.serial} onChange={(e) => setForm({ ...form, serial: e.target.value })} />
                        <Select
                            label={t('smart_qr.filter_type')}
                            value={form.qr_type}
                            onChange={(e) => setForm({ ...form, qr_type: e.target.value })}
                            options={(filterOptions.qrTypes ?? []).map((v) => ({ value: v, label: v }))}
                        />
                        <Select
                            label={t('smart_qr.filter_user')}
                            value={form.assigned_user_id}
                            onChange={(e) => setForm({ ...form, assigned_user_id: e.target.value })}
                            options={(filterOptions.users ?? []).map((u) => ({ value: u.id, label: u.label }))}
                        />
                        <Select
                            label={t('smart_qr.filter_status')}
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            options={(filterOptions.statuses ?? []).map((v) => ({ value: v, label: t(`smart_qr.assignment_status.${v}`, v) }))}
                        />
                        <Select
                            label={t('smart_qr.filter_channel')}
                            value={form.channel_account_id}
                            onChange={(e) => setForm({ ...form, channel_account_id: e.target.value })}
                            options={(filterOptions.channels ?? []).map((c) => ({ value: c.id, label: c.label }))}
                        />

                        <div className="flex items-end gap-2">
                            <Button type="submit" size="sm">{t('smart_qr.filters_apply')}</Button>
                            <Button type="button" size="sm" variant="outline" onClick={reset}>{t('smart_qr.filters_reset')}</Button>
                        </div>
                    </form>
                </Card>

                {/* ── §12 §1 Overview + visual A: KPI cards ──────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_overview')}</h3>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <KpiCard label={t('smart_qr.kpi_total_scans')} value={totals.scans ?? 0} />
                        <KpiCard label={t('smart_qr.kpi_unique_scans')} value={totals.unique_scans ?? 0} />
                        {/* ⚠️ R-19 — "Attributed", never "Customers Messaged". */}
                        <KpiCard label={t('smart_qr.kpi_attributed_messages')} value={totals.attributed_messages ?? 0} />
                        <KpiCard
                            label={t('smart_qr.kpi_attributed_rate')}
                            value={report?.rate === null || report?.rate === undefined ? '—' : report.rate}
                            unit={report?.rate === null || report?.rate === undefined ? '' : '%'}
                        />
                    </div>
                    <Card className="mt-4 bg-neutral-50 dark:bg-neutral-800/50">
                        <p className="text-sm text-neutral-600 dark:text-neutral-300">{t('smart_qr.attribution_note')}</p>
                    </Card>
                </section>

                {/* ── §12 §2 Scan Analytics + visual B ───────────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_scan_analytics')}</h3>
                    <Card>
                        <LineChart
                            data={report?.trend ?? []}
                            xKey="date"
                            yKeys={['scans', 'unique_scans']}
                            labels={{ scans: t('smart_qr.chart_scans'), unique_scans: t('smart_qr.chart_unique') }}
                        />
                    </Card>
                </section>

                {/* ── §12 §3 Customer Messaged + visual C ────────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_customer_messaged')}</h3>
                    <Card>
                        <BarChart
                            data={report?.trend ?? []}
                            xKey="date"
                            yKeys={['attributed_messages']}
                            labels={{ attributed_messages: t('smart_qr.chart_attributed') }}
                        />
                    </Card>
                </section>

                {/* ── §12 §4 Conversion Funnel + visual D ────────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_funnel')}</h3>
                    <Card>
                        <FunnelChart data={funnelData} />
                        {/* ⚠️ R-25 — three stages, and the page says why rather
                            than leaving a reader to wonder where §12's fourth
                            went. */}
                        <p className="mt-3 text-xs text-neutral-400 dark:text-neutral-500">{t('smart_qr.funnel_note')}</p>
                    </Card>
                </section>

                {/* ── §12 §5 QR Performance + visuals E and F ────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_qr_performance')}</h3>
                    <Card>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_name')}</th>
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_qr_type')}</th>
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_scans')}</th>
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_unique_scans')}</th>
                                        <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_attributed')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {perQr.map((r) => (
                                        <tr key={r.assignment_id} className="border-b border-neutral-100 dark:border-neutral-800">
                                            <td className="px-4 py-3 font-mono text-neutral-900 dark:text-neutral-100">{r.serial_number ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">{r.name ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{r.qr_type ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{r.scans}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{r.unique_scans}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{r.attributed_messages}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {perQr.length === 0 && (
                            <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">{t('smart_qr.no_report_data')}</div>
                        )}
                    </Card>
                </section>

                {/* ── §12 §6 User-wise Performance + visual G ────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_user_performance')}</h3>
                    <Card>
                        {perUser.length === 0 ? (
                            /* ⚠️ THE EMPTY STATE NAMES ITS CAUSE.
                               `assigned_user_id` is optional (§6 step 6) and
                               nothing in the product sets it, so this table is
                               empty on most installations. An empty table that
                               explains itself is honest; one that just looks
                               blank reads as a broken report. */
                            <div className="py-8 text-center">
                                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('smart_qr.no_user_data')}</p>
                                <p className="mx-auto mt-1 max-w-md text-xs text-neutral-500 dark:text-neutral-400">{t('smart_qr.no_user_data_hint')}</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                            <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_name')}</th>
                                            <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_codes')}</th>
                                            <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_scans')}</th>
                                            <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_attributed')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {perUser.map((u) => (
                                            <tr key={u.user_id} className="border-b border-neutral-100 dark:border-neutral-800">
                                                <td className="px-4 py-3 text-neutral-900 dark:text-neutral-100">{u.name ?? '—'}</td>
                                                <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{u.codes}</td>
                                                <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{u.scans}</td>
                                                <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{u.attributed_messages}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </section>

                {/* ── §12 §7 Activity Logs + visual H ────────────────────── */}
                <section>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.section_activity_logs')}</h3>
                    <Card>
                        {/* ⚠️ Deliberately a LINK, not a second feed. §12 forbids
                            duplicating content, the Activity page already renders
                            it, and that page reads RAW scans — which this report
                            cannot, because aggregates hold no per-scan rows. */}
                        <a href={route('client.smartqr.activity')} className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                            {t('smart_qr.activity_title')} →
                        </a>
                    </Card>
                </section>
            </div>
        </ClientLayout>
    );
}
