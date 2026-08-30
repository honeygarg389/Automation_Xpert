import { useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, ConfirmDestructiveModal, Input, Modal, Pagination, Select } from '@/Components/ui';
import { QrCode, Link2, Printer, Trash2, Download, Layers, Eye } from 'lucide-react';
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
/**
 * Bytes -> a size an admin can act on. No shared helper exists in this codebase
 * and one screen does not justify inventing a util module.
 */
function formatBytes(bytes) {
    if (! bytes) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

export default function SmartQrInventoryIndex({
    codes,
    filters = {},
    batches = [],
    statuses = [],
    workspaces = [],
    // ⚠️ Measured server-side — see SmartQrImageRenderer::ZIPPED_BYTES_PER_CODE.
    // The fallback is the no-logo pair, so a stale cached page understates
    // rather than invents.
    exportBytesPerCode = { svg: 4495, png: 6809, pdf: 4984 },
    exportMaxCodes = 500,
    readyExports = [],
}) {
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
        // ⚠️ The controller has validated and queried `workspace_id` since slice
        // 3a, but no control ever rendered it. Adding the input only — the filter
        // key, validation and query are untouched.
        workspace_id: filters.workspace_id ?? '',
    });

    const [selected, setSelected] = useState([]);
    const [exportOpen, setExportOpen] = useState(false);
    const [exportFormat, setExportFormat] = useState('svg');
    const bytesPerCode = exportBytesPerCode;
    const overExportCap = selected.length > exportMaxCodes;
    const [assignOpen, setAssignOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const rows = useMemo(() => codes?.data ?? [], [codes]);

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

    /**
     * ⚠️ THE SELECTION IS CLEARED ON SUCCESS, AND DELIBERATELY KEPT ON FAILURE.
     *
     * Raised from a browser walkthrough: after assigning, the ticked rows stayed
     * ticked. They had been acted on and no longer belonged to the selection, but
     * the UI still offered them — so a second action could be fired at rows whose
     * state had already changed underneath it.
     *
     * The two halves are opposite on purpose:
     *
     *   SUCCESS — clear. The rows have moved on; keeping them ticked invites
     *             acting on them twice.
     *   FAILURE — KEEP. Nothing was written (R-11 makes the whole batch atomic),
     *             so the admin's selection is still exactly the set they meant.
     *             Clearing it would make them re-tick forty rows to correct one
     *             field.
     *
     * Inertia's `onSuccess` fires only on a 2xx with no validation errors, which
     * gives that split for free — but it is stated here because it reads like an
     * omission otherwise.
     */
    const clearSelection = () => setSelected([]);

    const bulk = (routeName, payload = {}) => {
        if (selected.length === 0) return;
        router.post(route(routeName), { code_ids: selected, ...payload }, {
            preserveScroll: true,
            onSuccess: clearSelection,
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
                    {/* ⚠️ NO "Delete QR history" button. The reference shows one and
                        no such action exists — no route, no controller method, no
                        concept anywhere in the module. The only inventory delete is
                        `inventory.destroy`, which deletes SELECTED CODES and lives in
                        the selection bar. A destructive-looking header control wired to
                        nothing is not a placeholder worth having. */}
                    <Link href={route('admin.qr.batches.index')}>
                        <Button variant="outline" size="sm">
                            <Layers className="mr-1.5 h-4 w-4" /> {t('smart_qr.view_batches')}
                        </Button>
                    </Link>
                </div>

                {/* ── §5 filters ────────────────────────────────────────── */}
                <Card>
                    <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4">
                        <Input
                            value={form.search}
                            onChange={(e) => setForm({ ...form, search: e.target.value })}
                            placeholder={t('smart_qr.filter_serial')}
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
                        <Input
                            value={form.qr_type}
                            onChange={(e) => setForm({ ...form, qr_type: e.target.value })}
                            placeholder={t('smart_qr.filter_qr_type')}
                        />
                        {/* Workspace — the seventh filter the controller has always
                            accepted. `workspaces` was already passed to this page for
                            the assign modal, so nothing new is fetched. */}
                        <Select
                            value={form.workspace_id}
                            onChange={(e) => setForm({ ...form, workspace_id: e.target.value })}
                            placeholder={t('smart_qr.filter_all_workspaces')}
                            options={workspaces.map((w) => ({
                                value: w.id,
                                label: w.client_name ? `${w.name} — ${w.client_name}` : w.name,
                            }))}
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
                        {/* §5's export bulk action. A MODAL, not a bare select:
                            the ruling is that the admin sees the size before
                            they wait, and an onChange handler fires the job
                            before the note has been read. */}
                        <Button size="sm" variant="outline" onClick={() => setExportOpen(true)}>
                            <Download className="mr-1.5 h-4 w-4" /> {t('smart_qr.bulk_export')}
                        </Button>
                        {canManage && (
                            <Button size="sm" variant="outline" className="text-red-600 hover:text-red-700" onClick={() => setDeleteOpen(true)}>
                                <Trash2 className="mr-1.5 h-4 w-4" /> {t('smart_qr.delete_codes')}
                            </Button>
                        )}
                        {/* ml-auto: pushed away from the action cluster so
                            "clear" is not mistaken for another bulk action. */}
                        <button
                            type="button"
                            onClick={() => setSelected([])}
                            className="ml-auto text-sm text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300"
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
                                    {/* ⚠️ px-4, not pr-4. With no LEFT padding the
                                        checkbox sits flush against the container
                                        edge and `overflow-x-auto` clips its
                                        keyboard-focus ring. Matches the padding
                                        Pages/Contacts/Index.jsx uses on its own
                                        select column (`px-4 py-3 w-10`), which is
                                        why that table does not clip. */}
                                    <th className="w-10 px-4 pb-2">
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
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_action')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((code) => (
                                    <tr key={code.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="px-4 py-3">
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
                                        {/* ⚠️ WAS A DISABLED PLACEHOLDER. The note here recorded that
                                            building it needed "a route, a controller method
                                            and an admin preview endpoint — Slice B". All
                                            three now exist; this is Slice B.

                                            ⚠️ The route param is the SERIAL, not the id.
                                            SmartQrCode::getRouteKeyName() returns
                                            serial_number, so passing code.id 404s at route
                                            binding before any controller runs. */}
                                        <td className="py-3 pr-4">
                                            <Link href={route('admin.qr.inventory.show', code.serial_number)}>
                                                <Button variant="outline" size="sm">
                                                    <Eye className="mr-1.5 h-4 w-4" /> {t('common.view')}
                                                </Button>
                                            </Link>
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

            {/* ⚠️ READY EXPORTS — the half that was missing entirely.
                The job wrote a valid ZIP and the success message said it would
                "appear in storage". storage/app/private is not web-reachable,
                so every archive ever built was unreachable by the admin who
                asked for it. Four of them were sitting on this machine. */}
            {readyExports.length > 0 && (
                <Card className="mt-4">
                    <h3 className="mb-3 text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                        {t('smart_qr.ready_exports')}
                    </h3>
                    <ul className="divide-y divide-neutral-100 dark:divide-neutral-700">
                        {readyExports.map((x) => (
                            <li key={x.name} className="flex items-center justify-between py-2 text-sm">
                                <span className="font-mono text-neutral-700 dark:text-neutral-200">{x.name}</span>
                                <span className="flex items-center gap-4">
                                    <span className="text-neutral-500 dark:text-neutral-400">
                                        {formatBytes(x.size)} · {x.built_at}
                                    </span>
                                    <a
                                        href={route('admin.qr.inventory.export-download', x.name)}
                                        className="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:text-brand-700"
                                    >
                                        <Download className="h-4 w-4" /> {t('smart_qr.download_label')}
                                    </a>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {/* ── §5 export — format choice WITH its real cost ────── */}
            <Modal show={exportOpen} onClose={() => setExportOpen(false)} maxWidth="lg">
                <Modal.Header title={t('smart_qr.export_title')} onClose={() => setExportOpen(false)} />
                <Modal.Body className="space-y-4">
                    <p className="text-sm text-neutral-600 dark:text-neutral-300">
                        {t('smart_qr.export_body', { count: selected.length })}
                    </p>

                    <Select
                        label={t('smart_qr.export_format')}
                        value={exportFormat}
                        onChange={(e) => setExportFormat(e.target.value)}
                        placeholder=""
                        options={[
                            { value: 'svg', label: t('smart_qr.export_format_svg') },
                            { value: 'png', label: t('smart_qr.export_format_png') },
                            { value: 'pdf', label: t('smart_qr.export_format_pdf') },
                        ]}
                    />

                    {/* ⚠️ THE SIZE, AT THE POINT OF CHOICE, from measured
                        bytes — not a static string. With a platform logo
                        configured SVG is ~3x LARGER than PNG, so a
                        hard-coded "PNG is the big one" note would be a lie
                        in the production configuration. */}
                    <div className="rounded-soft-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
                        <p className="font-medium text-neutral-800 dark:text-neutral-100">
                            {t('smart_qr.export_size_estimate', {
                                size: formatBytes(bytesPerCode[exportFormat] * selected.length),
                            })}
                        </p>
                        <p className="mt-1 text-neutral-500 dark:text-neutral-400">
                            {/* ⚠️ Was hard-coded to "the other" of two formats.
                                With three, a PDF selection would have compared
                                against SVG and called it the only alternative. */}
                            {t('smart_qr.export_size_compare_multi', {
                                others: ['svg', 'png', 'pdf']
                                    .filter((f) => f !== exportFormat)
                                    .map((f) => `${f.toUpperCase()} ${formatBytes(bytesPerCode[f] * selected.length)}`)
                                    .join(', '),
                            })}
                        </p>
                        <p className="mt-2 text-neutral-500 dark:text-neutral-400">
                            {t(`smart_qr.export_note_${exportFormat}`)}
                        </p>
                        <p className="mt-2 text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.export_queued_note')}
                        </p>
                    </div>

                    {/* ⚠️ The server refuses over-cap too, and that refusal
                        is the real guard. This exists so the admin is not
                        told by a failed round-trip after selecting 5,000. */}
                    {overExportCap && (
                        <p className="rounded-soft-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
                            {t('smart_qr.export_over_cap', { max: exportMaxCodes, count: selected.length })}
                        </p>
                    )}
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="outline" onClick={() => setExportOpen(false)}>
                        {t('common.cancel')}
                    </Button>
                    <Button
                        disabled={overExportCap}
                        onClick={() => { bulk('admin.qr.inventory.export', { format: exportFormat }); setExportOpen(false); }}
                    >
                        {t('smart_qr.export_confirm')}
                    </Button>
                </Modal.Footer>
            </Modal>

            {/* ⚠️ Typed confirmation, because a delete has no undo. The server
                refuses anything printed or ever assigned regardless — this gate
                is about the codes that ARE deletable. */}
            {canManage && (

                <ConfirmDestructiveModal
                    show={deleteOpen}
                    onClose={() => setDeleteOpen(false)}
                    title={t('smart_qr.delete_codes')}
                    body={t('smart_qr.delete_codes_body', { count: selected.length })}
                    onConfirm={() => {
                        router.delete(route('admin.qr.inventory.destroy'), {
                            data: { code_ids: selected },
                            preserveScroll: true,
                            onSuccess: clearSelection,
                        });
                        setDeleteOpen(false);
                    }}
                />
            )}

            {canAssign && (
                <AssignQrModal
                    show={assignOpen}
                    onClose={() => setAssignOpen(false)}
                    // ⚠️ The modal owns the request, so only it knows the
                    // assignment succeeded — the selection lives here. Without
                    // this callback the rows stayed ticked after assignment,
                    // which is the defect this fixes.
                    onAssigned={clearSelection}
                    codeIds={selected}
                    workspaces={workspaces}
                />
            )}
        </AdminLayout>
    );
}
