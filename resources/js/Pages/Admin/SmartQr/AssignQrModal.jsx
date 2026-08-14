import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Button, Checkbox, Input, Modal, Select } from '@/Components/ui';
import { useTranslation } from 'react-i18next';
import axios from 'axios';

/**
 * ⚠️ R-9 — ONE MODAL FORM. Not the spec's ten-step wizard.
 *
 * §6 describes assignment as ten sequential steps ending in "confirm". This
 * codebase contains no wizard component anywhere, and introducing one to match
 * a described UX means maintaining a pattern with a single caller — a cost paid
 * forever for one screen.
 *
 * The ten steps are the ten fields below. Nothing in the described flow requires
 * sequencing: no step's options depend on a later step, and the only dependency
 * — channel and user must belong to the chosen workspace — is a validation, not
 * an ordering. It is expressed here by the pickers being empty until a workspace
 * is chosen, which is a data dependency, not a wizard.
 *
 * ⚠️ R-1 — the target is a WORKSPACE, not a client. Admins think in clients, so
 * the picker is grouped client -> workspace, but the value posted is the
 * workspace id, because that is the operational tenant boundary and the only
 * level at which "the channel belongs to the tenant" is well-defined.
 */
export default function AssignQrModal({ show, onClose, codeIds = [], workspaces = [] }) {
    const { t } = useTranslation();
    const permissions = usePage().props.auth?.permissions ?? [];

    // ⚠️ R-8: a SEPARATE permission from assigning. Someone who may assign is
    // not thereby allowed to break a workspace's limit.
    const canOverride = permissions.includes('override_qr_assignment_limit');

    /**
     * ⚠️ The fetched payload carries WHICH workspace it belongs to.
     *
     * That is what lets "still loading" be DERIVED rather than tracked in a
     * second state variable set synchronously inside the effect — which is both
     * a cascading render and a lint error (react-hooks/set-state-in-effect).
     * Stale responses are ignored for free: if the answer is for a workspace the
     * admin has already moved away from, it simply never becomes `ready`.
     */
    const EMPTY_OPTIONS = { channels: [], users: [], capacity: null };
    const [fetched, setFetched] = useState({ forWorkspace: null, ...EMPTY_OPTIONS });

    const { data, setData, post, transform, processing, errors, reset, clearErrors } = useForm({
        workspace_id: '',
        channel_account_id: '',
        assigned_user_id: '',
        name: '',
        qr_type: '',
        default_message: '',
        status: 'active',
        starts_at: '',
        expires_at: '',
        override_limit: false,
        override_reason: '',
    });

    /**
     * Channels, users and the capacity numbers for the chosen workspace.
     *
     * Fetched rather than shipped with the page: the inventory screen spans
     * every tenant, so pre-loading every workspace's channels and members would
     * put the whole platform's data into one page payload.
     */
    useEffect(() => {
        if (! data.workspace_id) return;

        let cancelled = false;

        axios
            .get(route('admin.qr.assignments.options', { workspace: data.workspace_id }), {
                params: { requested: codeIds.length },
            })
            .then(({ data: payload }) => {
                if (! cancelled) setFetched({ forWorkspace: data.workspace_id, ...payload });
            });

        return () => { cancelled = true; };
    }, [data.workspace_id, codeIds.length]);

    // Derived, not stored — see the note on `fetched` above.
    const ready = data.workspace_id !== '' && String(fetched.forWorkspace) === String(data.workspace_id);
    const options = ready ? fetched : EMPTY_OPTIONS;
    const loadingOptions = data.workspace_id !== '' && ! ready;

    const capacity = options.capacity;

    // ⚠️ `remaining === null` means UNLIMITED, not zero. null and 0 are opposite
    // extremes (BUG-030), and reading null as "none left" would show a warning
    // to precisely the customers who have no ceiling.
    const overCapacity =
        capacity && capacity.remaining !== null && codeIds.length > capacity.remaining;

    const submit = (e) => {
        e.preventDefault();

        // ⚠️ The selection lives in the TABLE, not in this form's state. Syncing
        // it with an effect meant a setState on every selection change; transform
        // merges it at submit time instead, which is what it is for.
        transform((current) => ({ ...current, code_ids: codeIds }));

        post(route('admin.qr.assignments.store'), {
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    const close = () => { clearErrors(); onClose(); };

    return (
        <Modal show={show} onClose={close} maxWidth="2xl">
            <Modal.Header title={t('smart_qr.assign_title')} onClose={close} />

            <form onSubmit={submit}>
                <Modal.Body className="space-y-4">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {t('smart_qr.assign_subtitle', { count: codeIds.length })}
                    </p>

                    {/* ⚠️ R-11 — the whole selection succeeds or none of it does.
                        Said on the form, because an admin who is refused after
                        ticking forty codes needs to know nothing landed. */}
                    {errors.code_ids && (
                        <div className="rounded-soft-lg bg-red-50 dark:bg-red-900/30 px-4 py-2 text-sm text-red-800 dark:text-red-200">
                            {errors.code_ids}
                        </div>
                    )}

                    <Select
                        label={t('smart_qr.field_workspace')}
                        value={data.workspace_id}
                        onChange={(e) => setData('workspace_id', e.target.value)}
                        placeholder={t('smart_qr.choose_workspace')}
                        options={workspaces.map((w) => ({
                            value: w.id,
                            // Client name first: admins navigate by organisation,
                            // even though the boundary posted is the workspace.
                            label: w.client_name ? `${w.client_name} — ${w.name}` : w.name,
                        }))}
                        error={errors.workspace_id}
                        required
                    />

                    {capacity && (
                        <div
                            className={`rounded-soft-lg px-4 py-2 text-sm ${
                                overCapacity
                                    ? 'bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200'
                                    : 'bg-neutral-50 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300'
                            }`}
                        >
                            {capacity.remaining === null
                                ? t('smart_qr.capacity_unlimited', { current: capacity.current })
                                : t('smart_qr.capacity_summary', {
                                    current: capacity.current,
                                    limit: capacity.limit,
                                    remaining: capacity.remaining,
                                })}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Select
                            label={t('smart_qr.field_channel')}
                            value={data.channel_account_id}
                            onChange={(e) => setData('channel_account_id', e.target.value)}
                            placeholder={loadingOptions ? t('common.loading') : t('smart_qr.choose_channel')}
                            // Only ACTIVE channels are selectable: §6 forbids
                            // pointing a printed sticker at a dead channel, and
                            // the server refuses it regardless.
                            options={options.channels
                                .filter((c) => c.status === 'active')
                                .map((c) => ({ value: c.id, label: c.label }))}
                            error={errors.channel_account_id}
                            disabled={! data.workspace_id}
                            required
                        />

                        <Select
                            label={t('smart_qr.field_assigned_user')}
                            value={data.assigned_user_id}
                            onChange={(e) => setData('assigned_user_id', e.target.value)}
                            placeholder={t('smart_qr.no_assigned_user')}
                            options={options.users.map((u) => ({ value: u.id, label: u.label }))}
                            error={errors.assigned_user_id}
                            disabled={! data.workspace_id}
                        />

                        <Input
                            label={t('smart_qr.field_name')}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder={t('smart_qr.name_placeholder')}
                            error={errors.name}
                        />

                        {/* ⚠️ R-3 — a free-text placement label, not an enum.
                            The vocabulary (Counter, Table, Reception, …) was an
                            owner ruling made after the spec, stored as a plain
                            string so it can change without a migration. A <select>
                            here would pin it in the UI instead. */}
                        <Input
                            label={t('smart_qr.field_qr_type')}
                            value={data.qr_type}
                            onChange={(e) => setData('qr_type', e.target.value)}
                            placeholder={t('smart_qr.qr_type_placeholder')}
                            list="smart-qr-types"
                            error={errors.qr_type}
                        />
                        <datalist id="smart-qr-types">
                            {['Counter', 'Table', 'Reception', 'Staff', 'Packaging', 'Storefront', 'Event', 'Product', 'Custom'].map((v) => (
                                <option key={v} value={v} />
                            ))}
                        </datalist>

                        <Input
                            type="date"
                            label={t('smart_qr.field_starts_at')}
                            value={data.starts_at}
                            onChange={(e) => setData('starts_at', e.target.value)}
                            error={errors.starts_at}
                        />

                        <Input
                            type="date"
                            label={t('smart_qr.field_expires_at')}
                            value={data.expires_at}
                            onChange={(e) => setData('expires_at', e.target.value)}
                            error={errors.expires_at}
                        />
                    </div>

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('smart_qr.field_default_message')}
                        </label>
                        <textarea
                            value={data.default_message}
                            onChange={(e) => setData('default_message', e.target.value)}
                            rows={3}
                            className="w-full rounded-soft border border-soft border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 shadow-inner transition duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                            placeholder={t('smart_qr.default_message_placeholder')}
                        />
                        {errors.default_message && (
                            <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{errors.default_message}</p>
                        )}
                    </div>

                    <Checkbox
                        name="status_active"
                        label={t('smart_qr.field_active')}
                        checked={data.status === 'active'}
                        onChange={(e) => setData('status', e.target.checked ? 'active' : 'inactive')}
                    />

                    {/* ══ ⚠️ R-8 — the override ══════════════════════════════
                        Shown only to an admin holding the separate permission.
                        Hiding it is presentation, not protection: the controller
                        refuses an override from an admin without the permission
                        regardless of what the form posts. */}
                    {canOverride && (
                        <div className="rounded-soft-lg border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-900/20 p-4 space-y-3">
                            <Checkbox
                                name="override_limit"
                                label={t('smart_qr.override_label')}
                                checked={data.override_limit}
                                onChange={(e) => setData('override_limit', e.target.checked)}
                                error={errors.override_limit}
                            />

                            {data.override_limit && (
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                        {t('smart_qr.override_reason_label')}
                                    </label>
                                    <textarea
                                        value={data.override_reason}
                                        onChange={(e) => setData('override_reason', e.target.value)}
                                        rows={2}
                                        // ⚠️ REQUIRED, and required in three
                                        // places: here, in AssignQrCodesRequest,
                                        // and at the action's signature. An
                                        // optional reason is an empty reason six
                                        // weeks later.
                                        required
                                        minLength={10}
                                        className="w-full rounded-soft border border-soft border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 shadow-inner focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                        placeholder={t('smart_qr.override_reason_placeholder')}
                                    />
                                    <p className="mt-1.5 text-xs text-amber-700 dark:text-amber-300">
                                        {t('smart_qr.override_audit_note')}
                                    </p>
                                    {errors.override_reason && (
                                        <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{errors.override_reason}</p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </Modal.Body>

                <Modal.Footer>
                    <Button type="button" variant="outline" onClick={close}>
                        {t('common.cancel')}
                    </Button>
                    <Button type="submit" disabled={processing || codeIds.length === 0}>
                        {t('smart_qr.assign_confirm', { count: codeIds.length })}
                    </Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}
