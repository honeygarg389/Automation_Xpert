import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Input, Pagination, Toggle } from '@/Components/ui';
import { Link2, Unlink, Pencil } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { formatDateTz } from '@/Utils/datetime';
import { AssignmentStatusBadge } from '../QrStatusBadge';
import EditAssignmentModal from '../EditAssignmentModal';

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



export default function SmartQrAssignmentsIndex({ assignments, filters }) {
    const { t } = useTranslation();
    const page = usePage();
    const flash = page.props.flash || {};
    const adminTz = page.props.timezone || 'UTC';
    const permissions = page.props.auth?.permissions ?? [];
    const canAssign = permissions.includes('assign_qr_codes');

    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters?.search ?? '');
    // uuid -> the status we optimistically showed, so a failed PATCH can revert.
    const [pending, setPending] = useState({});
    const rows = assignments?.data ?? [];
    // ⚠️ Read from Inertia's page URL rather than window.location: no browser
    // global, and it stays correct under Inertia's client-side navigation, where
    // window.location can lag a partial visit.
    const showingAll = (page.url ?? '').includes('current=all');

    /**
     * ⚠️ DEBOUNCED, and it skips the first run.
     *
     * Without the mount guard this fires a router.get on every page load — including
     * the one that just delivered these props — which throws away scroll position
     * and re-queries for a term the server already applied.
     *
     * ⚠️ The current/history filter is carried through explicitly. Dropping it would
     * make typing in the box silently reset a history view to current-only, which
     * looks like the search losing rows rather than the filter changing.
     */
    const firstRun = useRef(true);
    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return undefined;
        }

        const timer = setTimeout(() => {
            router.get(
                route('admin.qr.assignments.index'),
                {
                    ...(showingAll ? { current: 'all' } : {}),
                    ...(search ? { search } : {}),
                },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 400);

        return () => clearTimeout(timer);
    }, [search, showingAll]);

    /**
     * ⚠️ A LOCKED ASSIGNMENT ASKS FIRST.
     *
     * The lock stops the CUSTOMER toggling this QR; it does not stop an admin, and
     * the guard for it lives on the customer controller only. So the admin path
     * would otherwise flip a deliberately frozen code with no indication it was
     * frozen — which is how a chargeback hold gets undone by accident.
     *
     * ⚠️ window.confirm, NOT ConfirmDestructiveModal. That component is a
     * type-to-confirm gate reserved for irreversible deletes (delete batch, delete
     * codes). This is a reversible status flip, and the matching precedent is this
     * page's own unassign and Inventory/Show's unlock, both of which use confirm().
     */
    const clearPending = (uuid) => setPending((p) => Object.fromEntries(
        Object.entries(p).filter(([key]) => key !== uuid),
    ));

    const toggleStatus = (assignment, nextOn) => {
        const next = nextOn ? 'active' : 'inactive';

        if (assignment.admin_locked
            && ! window.confirm(t('smart_qr.toggle_locked_confirm', {
                serial: assignment.code?.serial_number ?? '',
            }))) {
            return;
        }

        setPending((p) => ({ ...p, [assignment.uuid]: next }));

        router.patch(
            route('admin.qr.assignments.update', assignment.uuid),
            { status: next },
            {
                preserveScroll: true,
                preserveState: true,
                // ⚠️ Cleared on BOTH paths. On success the server's status is now
                // authoritative; on failure dropping the optimistic entry is what
                // reverts the switch, because `checked` falls back to a.status.
                onError: () => {
                    clearPending(assignment.uuid);
                    toast.error(t('smart_qr.toggle_status_failed'));
                },
                onSuccess: () => clearPending(assignment.uuid),
            },
        );
    };

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
                    {/* ⚠️ The history button carries `search` through for the same
                        reason the debounce carries `current`: switching view must not
                        silently drop the active search term. */}
                    <div className="flex items-center gap-2">
                        {/* ⚠️ THE WIDTH LIVES ON THIS WRAPPER, not on <Input>.
                            Input renders its own `<div className="w-full">` around the
                            field and forwards `className` to the inner <input>, which
                            already carries w-full — so a width passed as a prop lands
                            on the wrong element and the wrapper still claims the whole
                            flex row. That is what pushed the button into wrapping. */}
                        <div className="w-56 shrink-0">
                            {/* ⚠️ BOTH classes are needed to reach the button's 34px.
                                ui/Input sets no text-size, so it inherits 16px with a
                                24px line-box; the sm Button is text-sm (14px/20px) with
                                py-1.5. 24+16+2 = 42 against 20+12+2 = 34, so changing
                                only the size or only the padding still leaves 4px.

                                ⚠️ py-[6px], NOT py-1.5 — they are the same 6px, and the
                                scale class DOES NOT WIN. Input's own py-2 sits later in
                                the compiled stylesheet (.py-2 at ~54.9k, .py-1\.5 at
                                ~54.7k), and class-string order is irrelevant to CSS
                                cascade, so py-1.5 measured 38px rather than 34px. An
                                arbitrary value is emitted after the standard scale and
                                wins on position without needing !important, for which
                                this codebase has no precedent. Same class-conflict trap
                                as the w-56 that had to move to a wrapper above. */}
                            <Input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('smart_qr.search_by_serial')}
                                aria-label={t('smart_qr.search_by_serial')}
                                className="text-sm py-[6px]"
                            />
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            className="shrink-0 whitespace-nowrap"
                            onClick={() => router.get(
                                route('admin.qr.assignments.index'),
                                {
                                    ...(showingAll ? {} : { current: 'all' }),
                                    ...(search ? { search } : {}),
                                },
                                { preserveState: true }
                            )}
                        >
                            {showingAll ? t('smart_qr.show_current_only') : t('smart_qr.show_history')}
                        </Button>
                    </div>
                </div>

                <Card>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="border-b border-neutral-200 dark:border-neutral-700 text-left text-neutral-500 dark:text-neutral-400">
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_serial')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_workspace')}</th>
                                    {/* ⚠️ Reuses field_destination_phone rather than adding a
                                        col_* key: the uppercase look comes from the
                                        `uppercase` class every header here carries, not from
                                        the translation string, so one key serves both the
                                        detail panel's "Destination phone" and this
                                        "DESTINATION PHONE". */}
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.field_destination_phone')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_qr_name')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_status')}</th>
                                    <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_assigned_at')}</th>
                                    {/* ⚠️ HIDDEN IN CURRENT-ONLY VIEW, and the body cell
                                        below is gated on the SAME `showingAll` for the
                                        same reason: the controller applies
                                        whereNull('unassigned_at') unless current=all, so
                                        every row here is guaranteed to have no end date
                                        and the column can only ever be a wall of dashes.
                                        Gate one and not the other and the header silently
                                        slips out of step with the rows. */}
                                    {showingAll && (
                                        <th className="pb-2 pr-4 font-medium uppercase">{t('smart_qr.col_ended_at')}</th>
                                    )}
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
                                        {/* ⚠️ Read off the SERIALIZED relation, not a
                                            flattened field the controller built. That is
                                            what routes the value through the model's
                                            toArray(), where MasksDemoData redacts it in
                                            demo mode.

                                            ⚠️ Printed raw. display_phone is stored
                                            free-form ("+91 88828 33998") and every UI
                                            consumer passes it through unchanged; only the
                                            redirect resolver strips it to digits, because
                                            wa.me demands that. */}
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {a.channel_account?.phone_number?.display_phone ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">{a.name ?? '—'}</td>
                                        {/* ⚠️ `ended` KEEPS THE BADGE. It is not a third
                                            toggle position: the period is closed
                                            (unassigned_at set) and history must not be
                                            re-opened by a switch. Only active/inactive
                                            are togglable, which is also exactly what
                                            UpdateQrAssignmentRequest accepts. */}
                                        <td className="py-3 pr-4">
                                            {a.status === 'ended' ? (
                                                <AssignmentStatusBadge status={a.status} />
                                            ) : (
                                                <Toggle
                                                    checked={(pending[a.uuid] ?? a.status) === 'active'}
                                                    disabled={! canAssign || pending[a.uuid] !== undefined}
                                                    onChange={(next) => toggleStatus(a, next)}
                                                />
                                            )}
                                        </td>
                                        <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                            {a.assigned_at ? formatDateTz(a.assigned_at, adminTz) : '—'}
                                        </td>
                                        {/* ⚠️ A dash here means the period is CURRENT
                                            (`unassigned_at IS NULL`) — the same
                                            predicate the gauge and the DB's unique
                                            index use. Only reachable in history view;
                                            see the header's note. */}
                                        {showingAll && (
                                            <td className="py-3 pr-4 text-neutral-600 dark:text-neutral-300">
                                                {a.unassigned_at ? formatDateTz(a.unassigned_at, adminTz) : '—'}
                                            </td>
                                        )}
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
