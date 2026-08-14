import { useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Pagination, Select } from '@/Components/ui';
import { QrCode, Link2, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { CodeStatusBadge, AssignmentStateBadge } from '../QrStatusBadge';
import AssignQrModal from '../AssignQrModal';

/**
 * §5 — QR inventory.
 *
 * Follows Admin/Clients/Index: Card + hand-rolled table + shared Pagination,
 * a filter form that round-trips through `router.get` with `preserveState`, and
 * permission flags read from `usePage().props.auth.permissions`.
 *
 * ⚠️ There is no shared Table component in this codebase — every admin screen
 * hand-rolls its `<table>` inside a Card. Matched rather than fixed: extracting
 * one is a refactor across ~20 screens, not something a QR slice should do on
 * its way past.
 */
export default function SmartQrInventoryIndex({ codes, filters = {}, batches = [], statuses = [], workspaces = [] }) {
    const { t } = useTranslation();
    const page = usePage();
    const flash = page.props.flash || {};
    const permissions = page.props.auth?.permissions ?? [];
    const canAssign = permissions.includes('assign_qr_codes');
    const canManage = permissions.includes('manage_qr_batches');

    const [form, setForm] = useState({
        search: filters.search ?? '',
        batch_id: filters.batch_id ?? '',
        status: filters.status ?? '',
        assignment: filters.assignment ?? '',
        printed: filters.printed ?? '',
        qr_type: filters.qr_type ?? '',
    });

    const [selected, setSelected] = useState([]);
    const [assignOpen, setAssignOpen] = useState(false);

    const rows = codes?.data ?? [];

    // ⚠️ Selection is by CODE ID, and only unassigned codes are selectable for
    // assignment. An assigned code is refused server-side ("unassign it first"),
    // so offering it would invite a refusal the UI could have prevented.
    const assignableIds = useMemo(
        () => rows.filter((c) => ! c.current_assignment).map((c) => c.id),
        [rows]
    );

    const allAssignableSelected =
        assignableIds.length > 0 && assignableIds.every((id) => selected.includes(id));

    const toggle = (id) =>
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const toggleAll = () =>
        setSelected(allAssignableSelected ? [] : assignableIds);

    const applyFilters = (e) => {
        e?.preventDefault();
        const params = Object.fromEntries(
            Object.entries(form).filter(([, v]) => v !== '' && v != null)
        );
        router.get(route('admin.qr.inventory.index'), params, { preserveState: true });
    };

    const clearFilters = () => {
        setForm({ search: '', batch_id: '', status: '', assignment: '', printed: '', qr_type: '' });
        router.get(route('admin.qr.inventory.index'), {}, { preserveState: true });
    };

    const bulk = (routeName, payload = {}) => {
        if (selected.length === 0) return;
        router.post(route(routeName), { code_ids: selected, ...payload }, {
            preserveScroll: true,
            onSuccess: () => setSelected([]),
        });
    };

    return (
        <AdminLayout title={t('smart_qr.inventory_title')}>
            <Head title={`${t('smart_qr.inventory_title')} · ${t('head.admin')}`} />

            <div className="space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 px-4 py-2 text-sm text-green-800 dark:text-green-200">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-soft-lg bg-red-50 dark:bg-red-900/30 px-4 py-2 text-sm text-red-800 dark:text-red-200">
                        {flash.error}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <QrCode className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('smart_qr.inventory_title')}
                            </h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('smart_qr.inventory_subtitle')}
                            </p>
                        </div>
                    </div>
                    <Link
                        href={route('admin.qr.batches.index')}
                        className="inline-flex items-center gap-2 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition"
                    >
                        {t('smart_qr.view_batches')}
                    </Link>
                </div>

                {/* ── §5 filters ────────────────────────────────────────── */}
                <Card>
                    <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <input
                            type="text"
                            value={form.search}
                            onChange={(e) => setForm({ ...form, search: e.target.value })}
                            placeholder={t('smart_qr.filter_serial')}
                            className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                        <Select
                            value={form.batch_id}
                            onChange={(e) => setForm({ ...form, batch_id: e.target.value })}
                            placeholder={t('smart_qr.filter_all_batches')}
                            options={batches.map((b) => ({ value: b.id, label: `${b.batch_number} — ${b.batch_name}` }))}
                        />
                        <Select
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            placeholder={t('smart_qr.filter_all_statuses')}
                            options={statuses.map((s) => ({ value: s, label: t(`smart_qr.code_status.${s}`, s) }))}
                        />
                        {/* ⚠️ R-10 — assigned/unassigned is its OWN filter, not a
                            status value, because it is derived rather than stored. */}
                        <Select
                            value={form.assignment}
                            onChange={(e) => setForm({ ...form, assignment: e.target.value })}
                            placeholder={t('smart_qr.filter_any_assignment')}
                            options={[
                                { value: 'assigned', label: t('smart_qr.assigned') },
                                { value: 'unassigned', label: t('smart_qr.unassigned') },
                            ]}
                        />
                        <Select
                            value={form.printed}
                            onChange={(e) => setForm({ ...form, printed: e.target.value })}
                            placeholder={t('smart_qr.filter_any_printed')}
                            options={[
                                { value: 'yes', label: t('smart_qr.printed_yes') },
                                { value: 'no', label: t('smart_qr.printed_no') },
                            ]}
                        />
                        <input
                            type="text"
                            value={form.qr_type}
                            onChange={(e) => setForm({ ...form, qr_type: e.target.value })}
                            placeholder={t('smart_qr.filter_qr_type')}
                            className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                        <div className="flex items-center gap-2">
                            <Button type="submit" variant="outline" size="sm">{t('common.search')}</Button>
                            <Button type="button" variant="outline" size="sm" onClick={clearFilters}>
                                {t('smart_qr.clear_filters')}
                            </Button>
                        </div>
                    </form>
                </Card>

                {/* ── §5 bulk actions ───────────────────────────────────── */}
                {selected.length > 0 && (
                    <Card className="flex flex-wrap items-center gap-3">
                        <span className="text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('smart_qr.selected_count', { count: selected.length })}
                        </span>
                        {canAssign && (
                            <Button size="sm" onClick={() => setAssignOpen(true)}>
                                <Link2 className="mr-1.5 h-4 w-4" /> {t('smart_qr.bulk_assign')}
                            </Button>
                        )}
                        {canManage && (
                            <>
                                <Button size="sm" variant="outline" onClick={() => bulk('admin.qr.inventory.mark-printed')}>
                                    <Printer className="mr-1.5 h-4 w-4" /> {t('smart_qr.bulk_mark_printed')}
                                </Button>
                                {/* ⚠️ Only PHYSICAL statuses (R-10). `assigned` is
                                    not offered because it does not exist as a
                                    status — the server rejects it too. */}
                                <Select
                                    className="w-44"
                                    value=""
                                    onChange={(e) => e.target.value && bulk('admin.qr.inventory.change-status', { status: e.target.value })}
                                    placeholder={t('smart_qr.bulk_change_status')}
                                    options={statuses.map((s) => ({ value: s, label: t(`smart_qr.code_status.${s}`, s) }))}
                                />
                            </>
                        )}
                        <button
                            type="button"
                            onClick={() => setSelected([])}
                            className="text-sm text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300"
                        >
                            {t('smart_qr.clear_selection')}
                        </button>
                    </Card>
                )}

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4">
                                        <input
                                            type="checkbox"
                                            checked={allAssignableSelected}
                                            onChange={toggleAll}
                                            disabled={assignableIds.length === 0}
                                            aria-label={t('smart_qr.select_all')}
                                            className="rounded"
                                        />
                                    </th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_batch')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_assignment')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_workspace')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_qr_type')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((code) => (
                                    <tr key={code.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="py-3 pr-4">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(code.id)}
                                                onChange={() => toggle(code.id)}
                                                disabled={!! code.current_assignment}
                                                aria-label={code.serial_number}
                                                className="rounded"
                                            />
                                        </td>
                                        <td className="py-3 pr-4 font-mono font-medium text-neutral-900 dark:text-neutral-100">
                                            {code.serial_number}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {code.batch?.batch_number ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4"><CodeStatusBadge status={code.status} /></td>
                                        <td className="py-3 pr-4">
                                            <AssignmentStateBadge currentAssignment={code.current_assignment} />
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {code.current_assignment?.workspace?.name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {code.current_assignment?.qr_type ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.no_codes')}
                        </div>
                    )}

                    <Pagination data={codes} />
                </Card>
            </div>

            {canAssign && (
                <AssignQrModal
                    show={assignOpen}
                    onClose={() => setAssignOpen(false)}
                    codeIds={selected}
                    workspaces={workspaces}
                />
            )}
        </AdminLayout>
    );
}
