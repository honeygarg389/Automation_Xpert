import { Link, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Toaster } from 'sonner';
import Sidebar from '@/Components/Sidebar';
import Topbar from '@/Components/Topbar';
import CommandPalette from '@/Components/CommandPalette';
import {
    LayoutDashboard,
    Users,
    Package,
    CreditCard,
    Globe,
    Banknote,
    Settings,
    FileText,
    ShieldCheck,
    UserCog,
    Tag,
    Receipt,
    Percent,
    Server,
    LifeBuoy,
    Plug,
    Radio,
    Brain,
    Clock,
    QrCode,
    Layers,
    Link2,
    LogOut,
} from 'lucide-react';

/** Nav item: { labelKey, route, href, icon, permission } - show only if user has permission (or no permission required). Order follows typical admin usage frequency. */
const ADMIN_NAV_ITEMS = [
    { labelKey: 'admin.dashboard', route: 'admin.dashboard', href: () => route('admin.dashboard'), icon: LayoutDashboard },
    { labelKey: 'admin.client_management', route: 'admin.clients.index', href: () => route('admin.clients.index'), icon: Users, permission: 'view_clients' },
    { labelKey: 'admin.nav.subscriptions', route: 'admin.subscriptions.index', href: () => route('admin.subscriptions.index'), icon: CreditCard, permission: 'view_subscriptions' },
    { labelKey: 'admin.nav.support', route: 'admin.support.index', href: () => route('admin.support.index'), icon: LifeBuoy, permission: 'view_settings' },
    { labelKey: 'admin.nav.payments', route: 'admin.payments.index', href: () => route('admin.payments.index'), icon: Receipt, permission: 'view_payment_gateways' },
    { labelKey: 'admin.nav.plans', route: 'admin.plans.index', href: () => route('admin.plans.index'), icon: Package, permission: 'view_plans' },
    { labelKey: 'admin.nav.coupons', route: 'admin.coupons.index', href: () => route('admin.coupons.index'), icon: Tag, permission: 'view_plans' },

    // ⚠️ The Smart QR entries USED TO SIT HERE as flat links, with a note saying
    // this sidebar had no grouping component and that building one for a single
    // caller was not worth it. Both halves are now out of date: the client panel's
    // grouping is reused (Sidebar's NavGroup), and the entries live in
    // QR_NAV_ITEMS below, emitted as a collapsible group straight after Plans.
    //
    // ⚠️ What has NOT changed is the reason only the built screens are listed:
    // a nav entry naming a route that does not exist is resolved by `route()` at
    // render and throws, taking the whole admin layout down. The Dashboard entry
    // in that group is a `disabled` placeholder carrying no route for exactly
    // this reason.
    { labelKey: 'admin.tax_rates', route: 'admin.tax-rates.index', href: () => route('admin.tax-rates.index'), icon: Percent, permission: 'view_plans' },
    { labelKey: 'admin.payment_gateways', route: 'admin.payment-gateways.index', href: () => route('admin.payment-gateways.index'), icon: CreditCard, permission: 'view_payment_gateways' },
    { labelKey: 'admin.email', route: 'admin.email-system.index', href: () => route('admin.email-system.index'), icon: FileText, permission: 'view_email_settings' },
    { labelKey: 'admin.nav.currencies', route: 'admin.currencies.index', href: () => route('admin.currencies.index'), icon: Banknote, permission: 'view_currencies' },
    { labelKey: 'admin.languages', route: 'admin.locales.index', href: () => route('admin.locales.index'), icon: Globe, permission: 'view_languages' },
    { labelKey: 'admin.roles_permissions', route: 'admin.roles-permissions.index', href: () => route('admin.roles-permissions.index'), icon: ShieldCheck, permission: 'view_admin_roles', permissionAlt: 'manage_admin_roles' },
    { labelKey: 'admin.nav.admins', route: 'admin.admins.index', href: () => route('admin.admins.index'), icon: UserCog, permission: 'view_admins' },
    { labelKey: 'admin.landing_page', route: 'admin.landing-page.index', href: () => route('admin.landing-page.index'), icon: FileText, permission: 'view_settings' },
    { labelKey: 'admin.cms_pages', route: 'admin.cms-pages.index', href: () => route('admin.cms-pages.index'), icon: FileText, permission: 'view_settings' },
    { labelKey: 'admin.nav.queue', route: 'admin.queue.index', href: () => route('admin.queue.index'), icon: Server, permission: 'view_settings' },
    { labelKey: 'admin.cron_setup', route: 'admin.cron-setup.index', href: () => route('admin.cron-setup.index'), icon: Clock, permission: 'view_settings' },
    { labelKey: 'admin.pusher_settings', route: 'admin.pusher-settings.index', href: () => route('admin.pusher-settings.index'), icon: Radio, permission: 'manage_settings' },
    { labelKey: 'admin.nav.settings', route: 'admin.settings.index', href: () => route('admin.settings.index'), icon: Settings, permission: 'view_settings' },
    { labelKey: 'admin.audit_log', route: 'admin.audit-log.index', href: () => route('admin.audit-log.index'), icon: FileText, permission: 'view_settings' },
    { labelKey: 'admin.nav.integrations', route: 'admin.integrations.index', href: () => route('admin.integrations.index'), icon: Plug, permission: 'manage_integrations' },
    { labelKey: 'admin.nav.ai', route: 'admin.ai.index', href: () => route('admin.ai.index'), icon: Brain, permission: 'view_settings' },
];

/**
 * QR Management — its own group in the sidebar.
 *
 * Same item shape as ADMIN_NAV_ITEMS and the same per-item `permission`, so the
 * existing filter applies unchanged. Lifted out of the flat list verbatim; route,
 * href, icon and permission are byte-identical to what they were there.
 */
const QR_NAV_ITEMS = [
    { labelKey: 'admin.nav.qr_batches', route: 'admin.qr.batches.index', href: () => route('admin.qr.batches.index'), icon: Layers, permission: 'view_qr_inventory' },
    { labelKey: 'admin.nav.qr_inventory', route: 'admin.qr.inventory.index', href: () => route('admin.qr.inventory.index'), icon: QrCode, permission: 'view_qr_inventory' },
    { labelKey: 'admin.nav.qr_assignments', route: 'admin.qr.assignments.index', href: () => route('admin.qr.assignments.index'), icon: Link2, permission: 'view_qr_inventory' },
];

function useAdminNav() {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const hasPermission = (key) => permissions.includes(key);

    return useMemo(() => {
        const allowed = (item) => {
            const perm = item.permission;
            const alt = item.permissionAlt;
            if (perm && !hasPermission(perm) && (!alt || !hasPermission(alt))) return false;
            return true;
        };

        const shape = (item) => ({
            label: t(item.labelKey),
            route: item.route,
            href: typeof item.href === 'function' ? item.href() : item.href,
            icon: item.icon ? <item.icon className="h-5 w-5" /> : null,
        });

        const qrItems = QR_NAV_ITEMS.filter(allowed).map(shape);

        // ⚠️ Filter FIRST, then decide whether the group exists at all — the same
        // guard useClientNav uses for its Smart QR group. A header with nothing
        // under it advertises a section the viewer cannot open.
        const qrGroup = qrItems.length
            ? {
                type: 'group',
                key: 'qr-management',
                label: t('admin.nav.qr_management'),
                items: [
                    /* ⚠️ STATIC PLACEHOLDER — `disabled`, carrying NO route and NO
                       href. `admin.qr.dashboard` does not exist; naming it here
                       would be resolved by route() during render and throw, taking
                       every admin page down (R-15). Sidebar returns early on
                       `disabled` before any route resolution. */
                    {
                        key: 'qr-dashboard-placeholder',
                        label: t('admin.nav.qr_dashboard'),
                        icon: <LayoutDashboard className="h-5 w-5" />,
                        disabled: true,
                        badge: t('common.soon'),
                    },
                    ...qrItems,
                ],
            }
            : null;

        /**
         * Built in one ordered pass so the group lands immediately after Plans,
         * rather than above or below the whole flat list.
         *
         * ⚠️ The group is emitted on the PLANS ENTRY ITSELF, not on the surviving
         * item before it — so its position holds even for an admin who lacks
         * `view_plans` and never sees Plans at all.
         */
        const items = [];
        ADMIN_NAV_ITEMS.forEach((item) => {
            if (allowed(item)) items.push(shape(item));
            if (item.route === 'admin.plans.index' && qrGroup) items.push(qrGroup);
        });

        // ⚠️ Filter FIRST, then decide whether the group exists at all — the same
        // guard useClientNav uses for its Smart QR group. A group header with no
        // items under it advertises a section the viewer cannot open.
        return items;
    }, [t, permissions]);
}

function AdminLayoutFooter() {
    const { t } = useTranslation();
    const { auth, app_version: appVersion } = usePage().props;
    const adminUser = auth?.adminUser;
    const initials = (() => {
        if (adminUser?.name) return adminUser.name.split(' ').filter(Boolean).map((n) => n[0]).join('').slice(0, 2).toUpperCase();
        if (adminUser?.email) return adminUser.email[0].toUpperCase();
        return 'A';
    })();
    return (
        <div className="border-t border-neutral-200 dark:border-neutral-800 pt-3 space-y-1">
            {adminUser && (
                <div className="flex items-center gap-3 px-3 py-2 rounded-soft">
                    <div className="flex-shrink-0 h-8 w-8 rounded-full bg-brand-100 dark:bg-brand-900/40 flex items-center justify-center text-xs font-semibold text-brand-700 dark:text-brand-300">
                        {initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        {adminUser.name && (
                            <p className="text-xs font-medium text-neutral-700 dark:text-neutral-200 truncate">{adminUser.name}</p>
                        )}
                        <p className="text-xs text-neutral-400 dark:text-neutral-500 truncate" title={adminUser.email}>
                            {adminUser.email}
                        </p>
                    </div>
                </div>
            )}
            <Link
                href={route('admin.logout')}
                method="post"
                as="button"
                className="flex items-center gap-2 w-full text-left rtl:text-right rounded-soft px-3 py-2 text-sm text-neutral-500 hover:bg-red-50 hover:text-red-600 dark:text-neutral-400 dark:hover:bg-red-950/40 dark:hover:text-red-400 transition duration-150"
            >
                <LogOut className="h-4 w-4 flex-shrink-0" />
                {t('nav.logout')}
            </Link>
            {appVersion && (
                <p className="px-3 pt-1 text-center text-[11px] text-neutral-400 dark:text-neutral-600">
                    v{appVersion}
                </p>
            )}
        </div>
    );
}

export default function AdminLayout({ title = 'Admin', header, children }) {
    const { t } = useTranslation();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const adminNav = useAdminNav();
    const { demo_mode: demoMode, branding } = usePage().props;
    const logoUrl = branding?.logo_url;

    return (
        <div className="min-h-screen bg-neutral-50 dark:bg-neutral-950">
            <Sidebar
                open={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
                title={t('nav.admin')}
                logo={logoUrl ? <img src={logoUrl} alt="Logo" className="h-8 max-w-[160px] object-contain" /> : null}
                showCreateButton={false}
                navItems={adminNav.map((item, i) => (
                    // Group entries pass through untouched: they carry no `route`,
                    // so route().current() on one would throw.
                    item.type === 'group'
                        ? item
                        : {
                            ...item,
                            key: `${item.route}-${item.label}-${i}`,
                            active: () => route().current(item.route),
                        }
                ))}
                footer={<AdminLayoutFooter />}
            />

            <div className="lg:pl-64 rtl:lg:pl-0 rtl:lg:pr-64">
                <Topbar
                    showLogo={false}
                    title={title}
                    showWorkspace={false}
                    showLocale={false}
                    showCurrency={false}
                    showAccount={false}
                />

                <main className={`p-4 sm:p-6 lg:p-8 ${demoMode ? 'pb-16' : ''}`}>
                    {header && typeof header === 'object' && (
                        <div className="mb-6">
                            {header}
                        </div>
                    )}
                    {children}
                </main>
            </div>

            <button
                type="button"
                onClick={() => setSidebarOpen(true)}
                className="fixed bottom-4 right-4 z-30 flex h-12 w-12 items-center justify-center rounded-soft-lg bg-white dark:bg-neutral-900 border border-soft border-gray-200 dark:border-neutral-800 shadow-soft-lg text-neutral-600 hover:bg-neutral-50 dark:text-neutral-400 dark:hover:bg-neutral-800 lg:hidden rtl:right-auto rtl:left-4"
                aria-label={t('open_menu')}
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <CommandPalette searchRoute={route('admin.search')} />
            <Toaster richColors position="top-right" />
            {/* Demo notice — pinned to the bottom of the viewport */}
            {demoMode && (
                <div className="fixed inset-x-0 bottom-0 z-20 flex items-center justify-center gap-2 bg-amber-500/90 text-amber-950 px-4 py-2 text-sm font-medium pointer-events-none">
                    <span>{t('demo.banner') || 'Demo mode: changes are disabled.'}</span>
                </div>
            )}
        </div>
    );
}
