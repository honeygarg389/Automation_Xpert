import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input, Modal, Pagination, Select } from '@/Components/ui';
import { Link2, Unlink, Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDateTz } from '@/Utils/datetime';
import { AssignmentStatusBadge } from '../QrStatusBadge';

/**
 * §6 — the assignments list.
 *
 * ⚠️ CURRENT assignments by default; `?current=all` includes ended periods.
 *
 * That default matters. `smart_qr_assignments` keeps history — a reassignment
 * closes the period and leaves the row (R-4), because the previous tenant's
 * scans stay attached to it. So an unfiltered list is "every assignment ever
 * made", which is the wrong default for a screen an operator uses to answer
 * "who holds this code now".
 */
const QR_TYPES = [
    'Counter', 'Table', 'Reception', 'Staff', 'Packaging',
    'Storefront', 'Event', 'Product', 'Custom',
];

/**
 * ⚠️ EDIT LIVES ON THE ASSIGNMENT, and that is where the data lives.
 *
 * `name`, `qr_type`, `default_message`, the dates and active/inactive are all
 * columns of `smart_qr_assignments` — PER-TENANT settings. The same physical
 * sticker is "Front counter" to one customer and something else to whoever holds
 * it next. Putting this form on the CODE would edit a row shared by every tenant
 * that ever held it, and a reassignment would inherit the previous tenant's
 * labels.
 *
 * ⚠️ Absent by design: serial_number and public_token (they belong to the
 * physical code — §11 and R-4 forbid editing either) and workspace_id (changing
 * the tenant is a reassignment, which has its own path).
 */
function EditAssignmentModal({ assignment, onClose }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm({
        name: assignment?.name ?? '',
        qr_type: assignment?.qr_type ?? '',
        default_message: assignment?.default_message ?? '',
        status: assignment?.status ?? 'active',
        starts_at: assignment?.starts_at ? assignment.starts_at.slice(0, 10) : '',
        expires_at: assignment?.expires_at ? assignment.expires_at.slice(0, 10) : '',
    });

    if (! assignment) return null;

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <Modal.Header title={t('smart_qr.edit_qr')} onClose={onClose} />
            <form onSubmit={(e) => { e.preventDefault(); patch(route('admin.qr.assignments.update', assignment.uuid), { onSuccess: onClose }); }}>
                <Modal.Body className="space-y-4">
                    {/* Read-only context: the serial identifies WHICH sticker is
                        being edited, and must never become an input. */}
                    <div className="rounded-soft-lg bg-neutral-50 px-4 py-2 font-mono text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        {assignment.code?.serial_number} · {assignment.workspace?.name}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label={t('smart_qr.field_name')} value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                        <Select
                            label={t('smart_qr.field_qr_type')}
                            value={data.qr_type}
                            onChange={(e) => setData('qr_type', e.target.value)}
                            placeholder={t('smart_qr.qr_type_placeholder')}
                            options={QR_TYPES}
                            error={errors.qr_type}
                        />
                        <Input type="date" label={t('smart_qr.field_starts_at')} value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} error={errors.starts_at} />
                        <Input type="date" label={t('smart_qr.field_expires_at')} value={data.expires_at} onChange={(e) => setData('expires_at', e.target.value)} error={errors.expires_at} />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('smart_qr.field_default_message')}
                        </label>
                        <textarea
                            value={data.default_message}
                            onChange={(e) => setData('default_message', e.target.value)}
                            rows={3}
                            className="w-full rounded-soft border border-soft border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-inner focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    <Select
                        label={t('smart_qr.col_status')}
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        placeholder=""
                        options={[
                            { value: 'active', label: t('smart_qr.assignment_status.active') },
                            { value: 'inactive', label: t('smart_qr.assignment_status.inactive') },
                        ]}
                        error={errors.status}
                    />
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" disabled={processing}>{t('smart_qr.save')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

export default function SmartQrAssignmentsIndex({ assignments }) {
    const { t } = useTranslation();
    const page = usePage();
    const flash = page.props.flash || {};
    const adminTz = page.props.timezone || 'UTC';
    const permissions = page.props.auth?.permissions ?? [];
    const canAssign = permissions.includes('assign_qr_codes');

    const [editing, setEditing] = useState(null);
    const rows = assignments?.data ?? [];
    // ⚠️ Read from Inertia's page URL rather than window.location: no browser
    // global, and it stays correct under Inertia's client-side navigation, where
    // window.location can lag a partial visit.
    const showingAll = (page.url ?? '').includes('current=all');

    const unassign = (assignment) => {
        if (! confirm(t('smart_qr.unassign_confirm', { serial: assignment.code?.serial_number }))) return;

        router.delete(route('admin.qr.assignments.destroy', assignment.uuid), {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout title={t('smart_qr.assignments_title')}>
            <Head title={`${t('smart_qr.assignments_title')} · ${t('head.admin')}`} />

            <div className="space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 px-4 py-2 text-sm text-green-800 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Link2 className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('smart_qr.assignments_title')}
                            </h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('smart_qr.assignments_subtitle')}
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.get(
                            route('admin.qr.assignments.index'),
                            showingAll ? {} : { current: 'all' },
                            { preserveState: true }
                        )}
                    >
                        {showingAll ? t('smart_qr.show_current_only') : t('smart_qr.show_history')}
                    </Button>
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_workspace')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_qr_name')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_assigned_at')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_ended_at')}</th>
                                    <th className="pb-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((a) => (
                                    <tr key={a.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="py-3 pr-4 font-mono font-medium text-neutral-900 dark:text-neutral-100">
                                            {a.code?.serial_number ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-700 dark:text-neutral-300">
                                            {a.workspace?.name ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">{a.name ?? '—'}</td>
                                        <td className="py-3 pr-4"><AssignmentStatusBadge status={a.status} /></td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {a.assigned_at ? formatDateTz(a.assigned_at, adminTz) : '—'}
                                        </td>
                                        {/* ⚠️ A dash here means the period is CURRENT
                                            (`unassigned_at IS NULL`) — the same
                                            predicate the gauge and the DB's unique
                                            index use. */}
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {a.unassigned_at ? formatDateTz(a.unassigned_at, adminTz) : '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-right">
                                            {canAssign && ! a.unassigned_at && (
                                                <button
                                                    type="button"
                                                    onClick={() => setEditing(a)}
                                                    aria-label={t('smart_qr.edit_qr')}
                                                    className="mr-2 inline-flex rounded-soft p-1.5 text-neutral-400 transition hover:text-brand-600"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                            )}
                                            {canAssign && ! a.unassigned_at && (
                                                <button
                                                    type="button"
                                                    onClick={() => unassign(a)}
                                                    className="inline-flex items-center gap-1.5 rounded-soft p-1.5 text-sm text-amber-600 transition hover:text-amber-700"
                                                >
                                                    <Unlink className="h-4 w-4" /> {t('smart_qr.unassign')}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.no_assignments')}
                        </div>
                    )}

                    <Pagination data={assignments} />
                </Card>
            </div>

            {canAssign && editing && (
                <EditAssignmentModal assignment={editing} onClose={() => setEditing(null)} />
            )}
        </AdminLayout>
    );
}
