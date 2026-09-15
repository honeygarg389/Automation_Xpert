import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, Input, Pagination } from '@/Components/ui';
import { Plus, Search, Store } from 'lucide-react';

const STATUS_VARIANTS = {
    pending: 'warning',
    connected: 'success',
    paused: 'danger',
    archived: 'default',
    disconnected: 'danger',
};

/**
 * Phase 1C — Restaurant Integrations list.
 *
 * Deliberately shows only metadata: workspace/outlet names, provider,
 * restID, environment, status, whether a token is CONFIGURED (never the
 * token or its hash), last webhook time, latest health state.
 */
export default function Index({ connections, filters }) {
    const page = usePage();
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('manage_pos_connections');
    const flash = page.props.flash || {};

    const [search, setSearch] = useState(filters?.search ?? '');
    const statusTab = filters?.status === 'archived' ? 'archived' : 'active';

    const submitSearch = (e) => {
        e.preventDefault();
        router.get(route('admin.restaurant.connections.index'), { search, status: statusTab }, { preserveState: true, replace: true });
    };

    const switchTab = (tab) => {
        router.get(route('admin.restaurant.connections.index'), { search, status: tab }, { preserveState: true, replace: true });
    };

    const rows = connections.data ?? [];

    return (
        <AdminLayout>
            <Head title="Restaurant Integrations" />

            <div className="mb-6 flex items-center justify-between">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Restaurant Integrations</h1>
                {canManage && (
                    <div className="flex gap-3">
                        <Link href={route('admin.restaurant.outlets.index')}>
                            <Button variant="secondary">
                                <Store className="mr-1.5 h-4 w-4" /> Add Outlet Only
                            </Button>
                        </Link>
                        <Link href={route('admin.restaurant.connections.create')}>
                            <Button variant="primary">
                                <Plus className="mr-1.5 h-4 w-4" /> New Petpooja Connection
                            </Button>
                        </Link>
                    </div>
                )}
            </div>

            {flash.success && (
                <div className="mb-4 rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    {flash.success}
                </div>
            )}
            {flash.error && (
                <div className="mb-4 rounded-soft border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-800 dark:bg-coral-950/40 dark:text-coral-300">
                    {flash.error}
                </div>
            )}

            <Card>
                <div className="mb-4 flex gap-1 border-b border-neutral-200 dark:border-neutral-800">
                    {[
                        { key: 'active', label: 'Active' },
                        { key: 'archived', label: 'Archived' },
                    ].map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => switchTab(tab.key)}
                            className={[
                                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition duration-150',
                                statusTab === tab.key
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700 dark:text-neutral-400 dark:hover:border-neutral-600 dark:hover:text-neutral-200',
                            ].join(' ')}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <form onSubmit={submitSearch} className="mb-4 flex max-w-sm gap-2">
                    <Input
                        name="search"
                        placeholder="Search workspace, outlet, restID..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="h-4 w-4" />
                    </Button>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                                <th className="py-2 pr-4 font-medium">Workspace</th>
                                <th className="py-2 pr-4 font-medium">Outlet</th>
                                <th className="py-2 pr-4 font-medium">Provider</th>
                                <th className="py-2 pr-4 font-medium">restID</th>
                                <th className="py-2 pr-4 font-medium">Environment</th>
                                <th className="py-2 pr-4 font-medium">Status</th>
                                <th className="py-2 pr-4 font-medium">Token</th>
                                <th className="py-2 pr-4 font-medium">Last webhook</th>
                                <th className="py-2 pr-4 font-medium">Health</th>
                                <th className="py-2 pr-4 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((c) => (
                                <tr key={c.uuid} className="border-b border-neutral-100 dark:border-neutral-800/60">
                                    <td className="py-2 pr-4">{c.workspace_name ?? '—'}</td>
                                    <td className="py-2 pr-4">{c.outlet_name ?? '—'}</td>
                                    <td className="py-2 pr-4 capitalize">{c.provider}</td>
                                    <td className="py-2 pr-4 font-mono text-xs">{c.external_ref}</td>
                                    <td className="py-2 pr-4">{c.environment === 'production' ? 'Live' : 'Test/sandbox'}</td>
                                    <td className="py-2 pr-4">
                                        <Badge variant={STATUS_VARIANTS[c.status] ?? 'default'} size="sm">{c.status}</Badge>
                                    </td>
                                    <td className="py-2 pr-4">
                                        <Badge variant={c.token_configured ? 'success' : 'warning'} size="sm">
                                            {c.token_configured ? 'Configured' : 'Not configured'}
                                        </Badge>
                                    </td>
                                    <td className="py-2 pr-4 text-neutral-500 dark:text-neutral-400">{c.last_event_at ?? 'Never'}</td>
                                    <td className="py-2 pr-4 text-neutral-500 dark:text-neutral-400">{c.last_test_status ?? 'untested'}</td>
                                    <td className="py-2 pr-4 text-right">
                                        <Link
                                            href={route('admin.restaurant.connections.show', c.uuid)}
                                            className="text-brand-600 hover:underline dark:text-brand-400"
                                        >
                                            {canManage ? 'Configure' : 'View'}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {statusTab === 'archived' ? 'No archived connections.' : 'No Restaurant/POS connections yet.'}
                        </div>
                    )}
                </div>

                <Pagination data={connections} className="mt-4" />
            </Card>
        </AdminLayout>
    );
}
