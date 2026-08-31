import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Button, Card, Input, Modal, Pagination, Select } from '@/Components/ui';
import { Layers, Lock, Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatDateTz } from '@/Utils/datetime';

/**
 * §11 B — My QR Codes.
 *
 * ⚠️ THE PREVIEW AND DOWNLOAD ARRIVED IN SLICE 8.
 *
 * Both need a rendered QR image, and `endroid/qr-code` is R-6's slice-8 package
 * — explicitly not to be installed before then. Until then they were OMITTED
 * rather than shipped as buttons that did nothing, because a customer cannot
 * tell a dead action from a bug.
 *
 * ⚠️ Editing is ADMINISTRATOR-ONLY. §11 says actions "depend on permissions",
 * but this codebase has no client permission system — `client_role` is the only
 * granularity that exists, so that is the honest reading.
 */
function EditModal({ code, channels, users, onClose }) {
    const { t } = useTranslation();
    const a = code?.current_assignment ?? {};
    const { data, setData, patch, processing, errors } = useForm({
        name: a.name ?? '',
        qr_type: a.qr_type ?? '',
        default_message: a.default_message ?? '',
        status: a.status ?? 'active',
        channel_account_id: a.channel_account_id ?? '',
        assigned_user_id: a.assigned_user_id ?? '',
    });

    if (! code) return null;

    return (
        <Modal show onClose={onClose} maxWidth="2xl">
            <Modal.Header title={t('smart_qr.edit_title')} onClose={onClose} />
            <form onSubmit={(e) => { e.preventDefault(); patch(route('client.smartqr.codes.update', code.serial_number), { onSuccess: onClose }); }}>
                <Modal.Body className="space-y-4">
                    {/* ⚠️ Read-only. §11 forbids editing the serial, and it names
                        a sticker already printed and stuck to something. */}
                    <div className="rounded-soft-lg bg-neutral-50 px-4 py-2 font-mono text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        {code.serial_number}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label={t('smart_qr.field_name')} value={data.name} onChange={(e) => setData('name', e.target.value)} error={errors.name} />
                        <Input label={t('smart_qr.field_qr_type')} value={data.qr_type} onChange={(e) => setData('qr_type', e.target.value)} error={errors.qr_type} />

                        {/* Cross-workspace validity is enforced server-side by
                            SmartQrAssignmentValidator; this list only offers the
                            workspace's own channels, from that same service. */}
                        <Select
                            label={t('smart_qr.field_channel')}
                            value={data.channel_account_id}
                            onChange={(e) => setData('channel_account_id', e.target.value)}
                            placeholder={t('smart_qr.choose_channel')}
                            options={channels.filter((c) => c.status === 'active').map((c) => ({ value: c.id, label: c.label }))}
                            error={errors.channel_account_id}
                        />
                        <Select
                            label={t('smart_qr.field_assigned_user')}
                            value={data.assigned_user_id}
                            onChange={(e) => setData('assigned_user_id', e.target.value)}
                            placeholder={t('smart_qr.no_assigned_user')}
                            options={users.map((u) => ({ value: u.id, label: u.label }))}
                            error={errors.assigned_user_id}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('smart_qr.field_default_message')}</label>
                        <textarea
                            value={data.default_message}
                            onChange={(e) => setData('default_message', e.target.value)}
                            rows={3}
                            placeholder={t('smart_qr.default_message_placeholder')}
                            className="w-full rounded-soft border border-soft border-neutral-300 px-3 py-2 text-sm text-neutral-900 shadow-inner focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100"
                        />
                    </div>

                    {/* ⚠️ DISABLED IS A COURTESY, NOT THE ENFORCEMENT. The server
                        refuses the change in SmartQrCodeController::update() and
                        returns the reason; this only spares the customer a form
                        submission that was always going to be rejected. Anyone
                        bypassing the UI still meets the guard, which has its own
                        test with the UI out of the picture.

                        ⚠️ Only this control is disabled — name, type and message stay
                        editable while locked, because the lock is about the toggle
                        and nothing else. */}
                    <Select
                        label={t('smart_qr.col_status')}
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        placeholder=""
                        disabled={Boolean(a.admin_locked)}
                        options={[
                            { value: 'active', label: t('smart_qr.assignment_status.active') },
                            { value: 'inactive', label: t('smart_qr.assignment_status.inactive') },
                        ]}
                        error={errors.status}
                    />

                    {a.admin_locked && (
                        <p className="-mt-2 text-sm text-amber-700 dark:text-amber-300">
                            {t('smart_qr.locked_customer_hint', { reason: a.lock_reason ?? '' })}
                        </p>
                    )}
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" disabled={processing}>{t('smart_qr.save')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

export default function SmartQrCodes({ codes, stats = {}, canManage, channels = [], users = [] }) {
    const { t } = useTranslation();
    const tz = usePage().props.timezone || 'UTC';
    const [editing, setEditing] = useState(null);
    const rows = codes?.data ?? [];

    return (
        <ClientLayout title={t('smart_qr.codes_title')}>
            <Head title={t('smart_qr.codes_title')} />

            <div className="space-y-6">
                <div className="flex items-center gap-3">
                    <Layers className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('smart_qr.codes_title')}</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('smart_qr.codes_subtitle')}</p>
                    </div>
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_preview')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_name')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_qr_type')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_scans')}</th>
                                    {/* ⚠️ R-19 — "Attributed", not "Customers Messaged". */}
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_attributed')}</th>
                                    <th className="px-4 pb-2 font-medium uppercase">{t('smart_qr.col_last_scan')}</th>
                                    <th className="px-4 pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((c) => {
                                    const a = c.current_assignment ?? {};
                                    const s = stats[a.id] ?? {};

                                    return (
                                        <tr key={c.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                            {/* ⚠️ SVG, rendered on demand by the
                                                preview endpoint — crisp at any row
                                                height and costing no storage. A
                                                cached PNG per code would be a
                                                storage lifecycle to manage for an
                                                operation that takes milliseconds. */}
                                            <td className="px-4 py-3">
                                                <img
                                                    src={route('client.smartqr.codes.preview', c.serial_number)}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-12 w-12 rounded border border-neutral-200 bg-white object-contain dark:border-neutral-700"
                                                />
                                            </td>
                                            <td className="px-4 py-3 font-mono font-medium text-neutral-900 dark:text-neutral-100">{c.serial_number}</td>
                                            <td className="px-4 py-3 text-neutral-700 dark:text-neutral-300">{a.name ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{a.qr_type ?? '—'}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                                {t(`smart_qr.assignment_status.${a.status}`, a.status ?? '—')}
                                                {/* ⚠️ Visible BEFORE opening the form, so a
                                                    customer learns the toggle is frozen from
                                                    the list rather than by clicking edit and
                                                    finding a dead control. */}
                                                {a.admin_locked && (
                                                    <span
                                                        title={a.lock_reason ?? ''}
                                                        className="ml-2 inline-flex items-center gap-1 rounded-soft bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-200"
                                                    >
                                                        <Lock className="h-3 w-3" /> {t('smart_qr.locked')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{s.scans ?? 0}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">{s.attributed_messages ?? 0}</td>
                                            <td className="px-4 py-3 text-neutral-600 dark:text-neutral-400">
                                                {s.last_scan_at ? formatDateTz(s.last_scan_at, tz) : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center justify-end gap-2">
                                                    {/* §11's "download digital copy". Three
                                                        formats, generated per request — only
                                                        bulk needs the queue. */}
                                                    <Select
                                                        aria-label={t('smart_qr.download_label')}
                                                        className="w-28"
                                                        value=""
                                                        onChange={(e) => {
                                                            if (! e.target.value) return;
                                                            window.location.href = route('client.smartqr.codes.download', c.serial_number) + '?format=' + e.target.value;
                                                        }}
                                                        placeholder={t('smart_qr.download_label')}
                                                        options={[
                                                            { value: 'svg', label: t('smart_qr.format_svg') },
                                                            { value: 'png', label: t('smart_qr.format_png') },
                                                            { value: 'pdf', label: t('smart_qr.format_pdf') },
                                                        ]}
                                                    />
                                                    {canManage && (
                                                        <button type="button" onClick={() => setEditing(c)} aria-label={t('smart_qr.edit_title')} className="p-1 text-neutral-400 transition hover:text-brand-600">
                                                            <Pencil className="h-4 w-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">{t('smart_qr.no_codes_yet')}</div>
                    )}

                    <Pagination data={codes} />
                </Card>
            </div>

            {canManage && editing && (
                <EditModal code={editing} channels={channels} users={users} onClose={() => setEditing(null)} />
            )}
        </ClientLayout>
    );
}
