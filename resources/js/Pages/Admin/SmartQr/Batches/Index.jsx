import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input, Modal, Pagination } from '@/Components/ui';
import { Layers, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { BatchStatusBadge } from '../QrStatusBadge';

/**
 * §4 — QR batches.
 *
 * ⚠️ CREATION IS A MODAL ON THIS PAGE, NOT A `Batches/Create` SCREEN.
 *
 * There is no GET `admin.qr.batches.create` route — 3a exposes `batches.index`,
 * `batches.store` (POST) and `batches.show` only. So a create *page* would have
 * nothing to route to, and adding one would mean adding a route for a screen
 * whose whole content is six fields.
 *
 * The same reasoning as R-9: a modal over the list, matching Admin/Clients,
 * rather than a new navigation step nothing else in this admin has.
 */
function CreateBatchModal({ show, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({
        batch_name: '',
        batch_number: '',
        prefix: '',
        quantity: 500,
        serial_start: 1,
        qr_type: '',
        default_message: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.qr.batches.store'), {
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <Modal.Header title={t('smart_qr.create_batch')} onClose={onClose} />
            <form onSubmit={submit}>
                <Modal.Body className="space-y-4">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {t('smart_qr.create_batch_subtitle')}
                    </p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input
                            label={t('smart_qr.field_batch_name')}
                            value={data.batch_name}
                            onChange={(e) => setData('batch_name', e.target.value)}
                            placeholder="AutomationXpert Business Kit August 2026"
                            error={errors.batch_name}
                            required
                        />
                        <Input
                            label={t('smart_qr.field_batch_number')}
                            value={data.batch_number}
                            onChange={(e) => setData('batch_number', e.target.value)}
                            placeholder="AX-BK-0826"
                            error={errors.batch_number}
                            required
                        />
                        {/* Uppercased on input to match the server rule
                            (`regex:/^[A-Z0-9]+$/`): the prefix is PRINTED, and a
                            lowercase one produces serials an operator cannot read
                            back off a sticker reliably. */}
                        <Input
                            label={t('smart_qr.field_prefix')}
                            value={data.prefix}
                            onChange={(e) => setData('prefix', e.target.value.toUpperCase())}
                            placeholder="AX"
                            className="font-mono"
                            error={errors.prefix}
                            required
                        />
                        <Input
                            type="number"
                            label={t('smart_qr.field_quantity')}
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                            min="1"
                            max="10000"
                            error={errors.quantity}
                            required
                        />
                        {/* ⚠️ BUG-035 lives on this field. The server refuses a
                            prefix+start+quantity range overlapping an existing
                            batch, naming the conflict — before a single code is
                            generated. The error text comes from the server, so it
                            is not duplicated here. */}
                        <Input
                            type="number"
                            label={t('smart_qr.field_serial_start')}
                            value={data.serial_start}
                            onChange={(e) => setData('serial_start', e.target.value)}
                            min="1"
                            hint={t('smart_qr.serial_start_hint')}
                            error={errors.serial_start}
                            required
                        />
                        <Input
                            label={t('smart_qr.field_qr_type')}
                            value={data.qr_type}
                            onChange={(e) => setData('qr_type', e.target.value)}
                            placeholder={t('smart_qr.qr_type_placeholder')}
                            error={errors.qr_type}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('smart_qr.field_default_message')}
                        </label>
                        <textarea
                            value={data.default_message}
                            onChange={(e) => setData('default_message', e.target.value)}
                            rows={2}
                            className="w-full rounded-soft border border-soft border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 shadow-inner focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                    </div>

                    {/* Preview of the range, so the operator sees what will be
                        printed before the queued job starts. Mirrors the server's
                        `sprintf('%s-%06d')`. */}
                    {data.prefix && data.quantity > 0 && (
                        <div className="rounded-soft-lg bg-neutral-50 dark:bg-neutral-800 px-4 py-2 font-mono text-sm text-neutral-600 dark:text-neutral-300">
                            {`${data.prefix}-${String(data.serial_start).padStart(6, '0')}`}
                            {' … '}
                            {`${data.prefix}-${String(Number(data.serial_start) + Number(data.quantity) - 1).padStart(6, '0')}`}
                        </div>
                    )}
                </Modal.Body>

                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
                    <Button type="submit" disabled={processing}>{t('smart_qr.create_batch')}</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

export default function SmartQrBatchesIndex({ batches }) {
    const { t } = useTranslation();
    const page = usePage();
    const flash = page.props.flash || {};
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('manage_qr_batches');

    const [createOpen, setCreateOpen] = useState(false);
    const rows = batches?.data ?? [];

    return (
        <AdminLayout title={t('smart_qr.batches_title')}>
            <Head title={`${t('smart_qr.batches_title')} · ${t('head.admin')}`} />

            <div className="space-y-6">
                {flash.success && (
                    <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 px-4 py-2 text-sm text-green-800 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Layers className="h-6 w-6 text-brand-600 dark:text-brand-400" />
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                                {t('smart_qr.batches_title')}
                            </h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                {t('smart_qr.batches_subtitle')}
                            </p>
                        </div>
                    </div>
                    {canManage && (
                        <Button onClick={() => setCreateOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" /> {t('smart_qr.create_batch')}
                        </Button>
                    )}
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_batch_number')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_name')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_range')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_generated')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_assigned')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="pb-2 pr-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((batch) => (
                                    <tr key={batch.id} className="border-b border-neutral-100 dark:border-neutral-800">
                                        <td className="py-3 pr-4 font-mono font-medium text-neutral-900 dark:text-neutral-100">
                                            {batch.batch_number}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-700 dark:text-neutral-300">{batch.batch_name}</td>
                                        <td className="py-3 pr-4 font-mono text-xs text-neutral-500 dark:text-neutral-400">
                                            {`${batch.prefix}-${String(batch.serial_start).padStart(6, '0')}`}
                                            {' … '}
                                            {`${batch.prefix}-${String(batch.serial_start + batch.quantity - 1).padStart(6, '0')}`}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {batch.generated_count} / {batch.quantity}
                                        </td>
                                        {/* ⚠️ R-12 — `assigned_count` is DERIVED by
                                            withCount on the server, never a stored
                                            column. It falls when a code is
                                            unassigned, which a hand-maintained
                                            counter would not. */}
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {batch.assigned_count ?? 0}
                                        </td>
                                        <td className="py-3 pr-4">
                                            <BatchStatusBadge status={batch.status} />
                                            {batch.failure_reason && (
                                                <p className="mt-1 max-w-xs truncate text-xs text-red-500" title={batch.failure_reason}>
                                                    {batch.failure_reason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-right">
                                            <Link
                                                href={route('admin.qr.batches.show', batch.uuid)}
                                                className="text-sm font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
                                            >
                                                {t('common.view')}
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {t('smart_qr.no_batches')}
                        </div>
                    )}

                    <Pagination data={batches} />
                </Card>
            </div>

            {canManage && <CreateBatchModal show={createOpen} onClose={() => setCreateOpen(false)} />}
        </AdminLayout>
    );
}
