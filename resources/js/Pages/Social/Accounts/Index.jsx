import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Share2, Plus, Trash2, AlertCircle, RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { SocialBrandIcon } from '@/Components/BrandIcons';

const NETWORKS = [
    { id: 'facebook',  label: 'Facebook',  descriptionKey: 'social.network_desc_facebook' },
    { id: 'instagram', label: 'Instagram', descriptionKey: 'social.network_desc_instagram' },
    { id: 'linkedin',  label: 'LinkedIn',  descriptionKey: 'social.network_desc_linkedin' },
    { id: 'twitter',   label: 'X (Twitter)', descriptionKey: 'social.network_desc_twitter' },
    { id: 'youtube',   label: 'YouTube',   descriptionKey: 'social.network_desc_youtube' },
    { id: 'tiktok',    label: 'TikTok',    descriptionKey: 'social.network_desc_tiktok' },
];

const STATUS_DOT = {
    active: 'bg-green-500',
    expired: 'bg-amber-500',
};

export default function SocialAccountsIndex({ accounts }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    // Group accounts by network — supports multiple per network
    const byNetwork = accounts.reduce((acc, a) => {
        if (!acc[a.network]) acc[a.network] = [];
        acc[a.network].push(a);
        return acc;
    }, {});

    const disconnect = (account) => {
        if (confirm(t('social.disconnect_confirm', { name: account.name }))) {
            router.delete(route('client.social.accounts.disconnect', account.id), { preserveScroll: true });
        }
    };

    const totalConnected = accounts.length;

    return (
        <ClientLayout title={t('social.accounts_title')}>
            <Head title={t('social.accounts_title')} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('social.accounts_heading')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">
                            {t('social.accounts_subtitle')}
                        </p>
                    </div>
                    {totalConnected > 0 && (
                        <a
                            href={route('client.social.composer')}
                            className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition"
                        >
                            <Share2 className="h-4 w-4" /> {t('social.new_post')}
                        </a>
                    )}
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2.5 text-sm">
                        {flash.error}
                    </div>
                )}

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {NETWORKS.map((net) => {
                        const connected = byNetwork[net.id] ?? [];
                        const hasExpired = connected.some(
                            (a) => a.token_expires_at && new Date(a.token_expires_at) < new Date()
                        );

                        return (
                            <div
                                key={net.id}
                                className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 flex flex-col"
                            >
                                {/* Header */}
                                <div className="flex items-center gap-3 p-4 border-b border-neutral-100 dark:border-neutral-800">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-neutral-50 dark:bg-neutral-800">
                                        <SocialBrandIcon network={net.id} className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold text-sm text-neutral-900 dark:text-neutral-100">{net.label}</p>
                                        <p className="text-xs text-neutral-400 truncate">{t(net.descriptionKey)}</p>
                                    </div>
                                    {hasExpired && (
                                        <AlertCircle className="h-4 w-4 text-amber-500 shrink-0" title={t('social.token_expired')} />
                                    )}
                                </div>

                                {/* Connected accounts list */}
                                {connected.length > 0 && (
                                    <ul className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        {connected.map((acct) => {
                                            const expired = acct.token_expires_at && new Date(acct.token_expires_at) < new Date();
                                            return (
                                                <li key={acct.id} className="flex items-center gap-3 px-4 py-2.5">
                                                    {acct.picture_url ? (
                                                        <img
                                                            src={acct.picture_url}
                                                            alt={acct.name}
                                                            className="h-7 w-7 rounded-full object-cover shrink-0"
                                                        />
                                                    ) : (
                                                        <span className="h-7 w-7 rounded-full bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center shrink-0 text-xs font-bold text-neutral-500">
                                                            {acct.name?.[0]?.toUpperCase() ?? '?'}
                                                        </span>
                                                    )}
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-xs font-medium text-neutral-800 dark:text-neutral-200 truncate">
                                                            {acct.name}
                                                        </p>
                                                        {expired ? (
                                                            <p className="text-xs text-amber-500">{t('social.token_expired')}</p>
                                                        ) : (
                                                            <p className="text-xs text-green-500">{t('common.active')}</p>
                                                        )}
                                                    </div>
                                                    <button
                                                        onClick={() => disconnect(acct)}
                                                        title={t('social.disconnect')}
                                                        className="shrink-0 text-neutral-300 hover:text-red-500 transition"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}

                                {/* Footer action */}
                                {(
                                    <div className="p-3 mt-auto">
                                        <a
                                            href={route('client.social.accounts.connect', net.id)}
                                            className="flex w-full items-center justify-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-500 hover:text-brand-600 dark:hover:text-brand-400 transition"
                                        >
                                            {connected.length > 0 ? (
                                                <>
                                                    <RefreshCw className="h-3 w-3" />
                                                    {hasExpired ? t('social.reconnect_add_another') : t('social.add_another_account')}
                                                </>
                                            ) : (
                                                <>
                                                    <Plus className="h-3.5 w-3.5" /> {t('social.connect_network', { network: net.label })}
                                                </>
                                            )}
                                        </a>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                {totalConnected === 0 && (
                    <div className="rounded-xl border-2 border-dashed border-neutral-200 dark:border-neutral-700 p-10 text-center">
                        <Share2 className="h-8 w-8 mx-auto text-neutral-300 dark:text-neutral-600 mb-3" />
                        <p className="font-medium text-neutral-700 dark:text-neutral-300">{t('social.no_accounts_yet')}</p>
                        <p className="text-sm text-neutral-400 mt-1">{t('social.no_accounts_yet_hint')}</p>
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
