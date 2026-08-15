import { Head, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Badge, Card, Pagination } from '@/Components/ui';
import { Radio } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDateTz } from '@/Utils/datetime';

/**
 * §11 C — Activity: the raw scan feed.
 *
 * ⚠️ BOTS ARE SHOWN HERE, FLAGGED — unlike the Overview counters, which exclude
 * them. The feed is a log rather than a metric: hiding crawler hits would make
 * an operator wonder why a scan they can see elsewhere is missing, and slice 4
 * deliberately RECORDS bots rather than dropping them because a preview crawler
 * is evidence the link was shared.
 *
 * ⚠️ This page cannot be served by slice 7's aggregates — a per-scan list needs
 * per-scan rows. It stays a direct query on the only unboundedly growing table
 * in the project, which is why it is paginated hard and why R-4's 90-day
 * retention matters here more than anywhere else.
 *
 * ⚠️ NO IP OR USER AGENT IS SHOWN, because none is stored — §10 forbids raw
 * addresses and slice 4 keeps only keyed hashes, which are meaningless to a
 * customer and would leak nothing useful if displayed.
 */
export default function SmartQrActivity({ scans }) {
    const { t } = useTranslation();
    const tz = usePage().props.timezone || 'UTC';
    const rows = scans?.data ?? [];

    return (
        <ClientLayout title={t('smart_qr.activity_title')}>
            <Head title={t('smart_qr.activity_title')} />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Radio className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.activity_title')}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_qr.activity_subtitle')}</p>
                    </div>
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_when')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_name')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_kind')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_source')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((s) => (
                                    <tr key={s.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">{formatDateTz(s.scanned_at, tz)}</td>
                                        <td className="px-4 py-3 font-mono text-neutral-900 dark:text-neutral-100">
                                            {s.assignment?.code?.serial_number ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{s.assignment?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {s.is_bot
                                                ? <Badge variant="default" size="sm">{t('smart_qr.scan_bot')}</Badge>
                                                : s.is_unique
                                                    ? <Badge variant="success" size="sm">{t('smart_qr.scan_unique')}</Badge>
                                                    : <Badge variant="brand" size="sm">{t('smart_qr.scan_repeat')}</Badge>}
                                        </td>
                                        <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{s.referer_host ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">{t('smart_qr.no_activity')}</div>
                    )}

                    <Pagination data={scans} />
                </Card>
            </div>
        </ClientLayout>
    );
}
