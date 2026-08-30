import { useForm } from '@inertiajs/react';
import { Button, Input, Modal, Select } from '@/Components/ui';
import { useTranslation } from 'react-i18next';

/**
 * ⚠️ EXTRACTED FROM Assignments/Index.jsx SO TWO SCREENS SHARE ONE DEFINITION.
 *
 * It was a local function in that page. The inventory detail screen needs the
 * same form, and the alternative — a second copy — is how two edit dialogs for
 * one row drift until they disagree about which fields exist. Nothing about the
 * form changed in the move; only its location did.
 *
 * ⚠️ ITS PROP SHAPE IS THE ASSIGNMENTS PAGE'S ROW SHAPE, deliberately unchanged:
 * it reads `assignment.code?.serial_number` and `assignment.workspace?.name` for
 * the read-only context line. A caller whose props are shaped differently adapts
 * at the call site rather than widening this contract — see Inventory/Show.jsx.
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
                            placeholder={t('smart_qr.default_message_placeholder')}
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

export default EditAssignmentModal;
