import { Head, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Card } from '@/Components/ui';
import { QrCode } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * §11 A — Overview.
 *
 * ⚠️ R-19 IS ENFORCED IN THE LABELS HERE.
 *
 * §11's card list says "Customers Messaged". That label is forbidden: attributed
 * counts UNDER-REPORT, because the customer can delete the reference from their
 * own WhatsApp message before sending it, and there is deliberately no fallback
 * that guesses (R-20).
 *
 * So every attributed card says "Attributed", and carries a sub-label saying
 * what that means. Presenting a floor as a total is how a customer concludes the
 * QR does not work and stops using the product.
 */
function Kpi({ label, value, hint, suffix }) {
    return (
        <Card>
            <p className="text-sm text-neutral-500 dark:text-neutral-400">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {value === null || value === undefined ? '—' : value}{value !== null && value !== undefined && suffix ? suffix : ''}
            </p>
            {hint && <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">{hint}</p>}
        </Card>
    );
}

export default function SmartQrOverview({ kpis, recentCodes = [] }) {
    const { t } = useTranslation();

    return (
        <ClientLayout title={t('smart_qr.title')}>
            <Head title={t('smart_qr.title')} />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <QrCode className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.title')}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_qr.overview_subtitle')}</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Kpi label={t('smart_qr.kpi_total_codes')} value={kpis.total_codes} />
                    <Kpi label={t('smart_qr.kpi_active')} value={kpis.active_codes} />
                    <Kpi label={t('smart_qr.kpi_inactive')} value={kpis.inactive_codes} />
                    <Kpi label={t('smart_qr.kpi_total_scans')} value={kpis.total_scans} />

                    <Kpi label={t('smart_qr.kpi_unique_scans')} value={kpis.unique_scans} hint={t('smart_qr.kpi_unique_hint')} />

                    {/* ⚠️ R-19 — "Attributed", never "Customers Messaged". */}
                    <Kpi
                        label={t('smart_qr.kpi_attributed_messages')}
                        value={kpis.attributed_messages}
                        hint={t('smart_qr.kpi_attributed_hint')}
                    />
                    <Kpi
                        label={t('smart_qr.kpi_attributed_contacts')}
                        value={kpis.attributed_new_contacts}
                        hint={t('smart_qr.kpi_attributed_hint')}
                    />
                    <Kpi
                        label={t('smart_qr.kpi_attributed_rate')}
                        value={kpis.attributed_message_rate}
                        suffix="%"
                        hint={t('smart_qr.kpi_rate_hint')}
                    />
                </div>

                {/* The under-count, stated once in plain words rather than only
                    in tooltips — help text is not read. */}
                <Card className="bg-neutral-50 dark:bg-neutral-800/50">
                    <p className="text-sm text-neutral-600 dark:text-neutral-300">{t('smart_qr.attribution_note')}</p>
                </Card>

                <Card>
                    <h3 className="mb-3 text-base font-semibold text-neutral-900 dark:text-neutral-100">
                        {t('smart_qr.recent_codes')}
                    </h3>
                    {recentCodes.length === 0 ? (
                        <p className="py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">{t('smart_qr.no_codes_yet')}</p>
                    ) : (
                        <ul className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {recentCodes.map((c) => (
                                <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                                    <span className="font-mono text-neutral-900 dark:text-neutral-100">{c.serial_number}</span>
                                    <span className="text-neutral-500 dark:text-neutral-400">{c.current_assignment?.name ?? '—'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link href={route('client.smartqr.codes.index')} className="mt-3 inline-block text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                        {t('smart_qr.view_all_codes')}
                    </Link>
                </Card>
            </div>
        </ClientLayout>
    );
}
