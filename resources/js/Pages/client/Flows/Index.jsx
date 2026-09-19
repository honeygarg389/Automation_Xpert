import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, ChevronDown, ClipboardList, Copy, Eye, FileInput, Info as InfoIcon, KeyRound, LayoutGrid,
    MoreVertical, Pencil, Phone, Plus, RefreshCw, Rocket, Search, Send, ShieldOff, Trash2,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { Badge, Button, ConfirmDestructiveModal, Modal, Tabs } from '@/Components/ui';

const META_LABELS = { syncing: 'Syncing', synced_draft: 'Synced draft', published: 'Published', failed: 'Sync failed', deprecated: 'Deprecated' };

const STATUS_PILL_CLASSES = {
    published: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    failed: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    deprecated: 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-300',
};

// The bottom banner's tone -> icon-circle color + icon, keyed the same as
// bannerClasses used to be. `danger` (meta_sync_error / validation errors)
// keeps its existing red treatment; success/neutral map to the reference's
// rocket/pencil icons.
const STATUS_BANNER_TONE = {
    success: { box: 'bg-emerald-50 dark:bg-emerald-900/10', iconWrap: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300', headline: 'text-emerald-700 dark:text-emerald-300', Icon: Rocket },
    danger: { box: 'bg-red-50 dark:bg-red-950/20', iconWrap: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300', headline: 'text-red-700 dark:text-red-300', Icon: AlertTriangle },
    neutral: { box: 'bg-neutral-50 dark:bg-neutral-800/50', iconWrap: 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700 dark:text-neutral-300', headline: 'text-neutral-900 dark:text-neutral-100', Icon: Pencil },
};

export default function FlowsIndex({ flows, categories }) {
    const { props } = usePage();
    const [creating, setCreating] = useState(false);
    const [actioning, setActioning] = useState(null);
    const [openMenu, setOpenMenu] = useState(null);
    const [deleteConfirm, setDeleteConfirm] = useState(null);
    const [syncingStatus, setSyncingStatus] = useState(false);
    const [search, setSearch] = useState('');
    const [infoFlow, setInfoFlow] = useState(null);
    const [name, setName] = useState('');
    const [category, setCategory] = useState('OTHER');
    const [createErrors, setCreateErrors] = useState({});
    const [createProcessing, setCreateProcessing] = useState(false);
    // Section E — "Send Test" from the Published card's ⋮ menu, mirroring
    // Builder.jsx's own modal exactly (same endpoint, same plausibility gate)
    // since a Published flow's Builder is read-only and this is one of the
    // few actions still offered for it there too.
    const [testSendFlow, setTestSendFlow] = useState(null);
    const [testSendPhone, setTestSendPhone] = useState('');
    const [testSendState, setTestSendState] = useState({ sending: false, error: null, success: null });

    const filteredFlows = useMemo(() => {
        const query = search.trim().toLowerCase();
        if (query === '') return flows;
        return flows.filter((flow) =>
            flow.name.toLowerCase().includes(query) ||
            (flow.category ?? '').toLowerCase().includes(query)
        );
    }, [flows, search]);

    const create = (event) => {
        event.preventDefault();
        setCreateProcessing(true);
        router.post(route('client.flows.store'), { name, category, status: 'draft', submit_settings: {} }, {
            onError: (errors) => setCreateErrors(errors),
            onSuccess: () => { setName(''); setCategory('OTHER'); setCreateErrors({}); setCreating(false); },
            onFinish: () => setCreateProcessing(false),
        });
    };

    const runAction = (event, flow, action) => {
        event.preventDefault();
        event.stopPropagation();
        setOpenMenu(null);
        setActioning(action + '-' + flow.uuid);
        router.post(route('client.flows.' + action, flow.uuid), {}, { preserveScroll: true, onFinish: () => setActioning(null) });
    };

    // Section E — "Sync from Meta" unifies what used to be two separate
    // header buttons ("Sync Meta Flows" the manual import picker, and "Sync
    // Status" the bulk reconcile) into one action.
    //
    // Task 3 refinement — this now does the WHOLE job in a single backend
    // call (WhatsappFlowMetaSyncService::syncAllFromMeta(), routed through
    // its own dedicated `sync-from-meta` endpoint): refresh every
    // already-linked flow's content from Meta AND automatically import
    // every Meta flow this workspace doesn't have locally yet, then report
    // one concrete "{updated} updated, {imported} imported, {errors}
    // errors" summary via the existing flash-banner convention. This
    // replaces the earlier manual checkbox picker for THIS action — a
    // single deterministic summary is not obtainable from an action whose
    // import half waits on an arbitrary later user choice. The picker's own
    // routes/controller actions (client.flows.import.picker/.store) are
    // untouched and still independently reachable; only this page's wiring
    // to them, which had no other caller, was removed.
    const syncFromMeta = () => {
        if (!window.confirm('Sync from Meta will overwrite saved local screens for every already-linked flow with the current Meta Flow JSON, and automatically import any new flows found on Meta. Continue?')) return;
        setSyncingStatus(true);
        router.post(route('client.flows.sync-from-meta'), {}, { preserveScroll: true, onFinish: () => setSyncingStatus(false) });
    };

    const deleteFlow = () => {
        if (!deleteConfirm) return;
        router.delete(route('client.flows.destroy', deleteConfirm.uuid), {
            preserveScroll: true,
            onSuccess: () => setDeleteConfirm(null),
        });
    };
    // Section G — the destroy() route itself already branches on the Flow's
    // real Meta state (see WhatsappFlowMetaSyncService::removeFromMeta());
    // this only decides which COPY the confirmation modal shows before that
    // call is made, since a Published flow is never actually deleted — it is
    // deprecated, irreversibly, and that must read as a materially different
    // and stronger warning than a routine delete.
    const isDeprecateConfirm = deleteConfirm?.meta_sync_status === 'published';
    const testSendUrl = () => testSendFlow && route('client.flows.test-send', testSendFlow.uuid);
    const submitTestSend = async (event) => {
        event.preventDefault();
        setTestSendState({ sending: true, error: null, success: null });
        try {
            const response = await fetch(testSendUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                },
                body: JSON.stringify({ phone_number: testSendPhone }),
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'Could not send the test message.');
            setTestSendState({ sending: false, error: null, success: body.message });
            setTimeout(() => setTestSendFlow(null), 1500);
        } catch (error) {
            setTestSendState({ sending: false, error: error.message, success: null });
        }
    };

    const webFormUrl = (flow) => (flow.public_slug ? route('public.flows.form.show', flow.public_slug) : null);

    /**
     * Section N — the SINGLE source of truth for "is this Flow live",
     * shared by the card's status badge AND its status-banner text so they
     * can never contradict each other. Before this, the badge read local
     * `flow.status` (this workspace's authored intent) while the banner
     * read `meta_sync_status` (Meta's own last-known platform state) — two
     * different fields answering the same visible question, with nothing
     * keeping them consistent. A flow whose local `status` was ever set to
     * "published" without a successful Meta sync (e.g. via a direct API
     * call, or a stale value predating a failed sync) could show a
     * "PUBLISHED" badge right next to a "Not yet live on Meta platform"
     * panel — this is exactly the contradiction being fixed.
     *
     * Once a Flow has ever synced (meta_flow_id set), Meta's own
     * meta_sync_status is authoritative for "is this live" — refreshed via
     * "Sync from Meta" / "Sync Status". A Flow that has never synced at all
     * has no Meta-side truth yet to defer to, so its local draft/published
     * intent is shown as-is; there is no Meta state for it to contradict.
     *
     * Deliberately NOT a new "is this stale" age indicator: there is no
     * dedicated "last confirmed against Meta" timestamp in the schema, and
     * `updated_at` is bumped by any local edit, not only a real Meta check —
     * inventing false precision here would be worse than omitting it. A
     * fuller meta_status/sync_status/validation_status column-level split
     * remains a separate, deferred backlog item (see Section N in the
     * accompanying report).
     */
    const displayStatus = (flow) => {
        if (!flow.meta_flow_id) {
            return flow.status === 'published'
                ? { pillLabel: 'Published', pillClass: STATUS_PILL_CLASSES.published, tone: 'neutral', headline: 'Published Status', subtext: 'Not yet synced to Meta.' }
                : { pillLabel: 'Draft', pillClass: STATUS_PILL_CLASSES.draft, tone: 'neutral', headline: 'Draft Status', subtext: 'Not yet live on Meta platform' };
        }

        if (flow.meta_sync_error) {
            return { pillLabel: 'Sync Failed', pillClass: STATUS_PILL_CLASSES.failed, tone: 'danger', headline: 'Sync Error', subtext: flow.meta_sync_error };
        }
        if (flow.meta_validation_errors?.length > 0) {
            return {
                pillLabel: 'Sync Failed', pillClass: STATUS_PILL_CLASSES.failed, tone: 'danger', headline: 'Validation Errors',
                subtext: `${flow.meta_validation_errors.length} validation error${flow.meta_validation_errors.length === 1 ? '' : 's'} from Meta.`,
            };
        }

        switch (flow.meta_sync_status) {
            case 'published':
                return { pillLabel: 'Published', pillClass: STATUS_PILL_CLASSES.published, tone: 'success', headline: 'Published to Meta', subtext: 'Live on WhatsApp' };
            case 'deprecated':
                return { pillLabel: 'Deprecated', pillClass: STATUS_PILL_CLASSES.deprecated, tone: 'neutral', headline: 'Deprecated', subtext: 'No longer sendable on Meta — this cannot be undone.' };
            case 'syncing':
                return { pillLabel: 'Syncing', pillClass: STATUS_PILL_CLASSES.draft, tone: 'neutral', headline: 'Syncing…', subtext: 'Pushing this Flow to Meta.' };
            case 'synced_draft':
                return { pillLabel: 'Synced Draft', pillClass: STATUS_PILL_CLASSES.draft, tone: 'neutral', headline: 'Synced Draft', subtext: 'Synced to Meta, not yet published.' };
            default:
                return { pillLabel: 'Draft', pillClass: STATUS_PILL_CLASSES.draft, tone: 'neutral', headline: 'Draft Status', subtext: 'Not yet live on Meta platform' };
        }
    };

    return (
        <ClientLayout title="WhatsApp Flows">
            <Head title="WhatsApp Flows" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">WhatsApp Flows</h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">Build static, multi-step forms for WhatsApp.</p>
                        </div>
                        {/* Task 6: de-emphasized from a top-level button to a small icon link next to the header — Slice 3's placement reasoning (encryption lifecycle/security management, not connection onboarding) still holds; only its prominence changes. */}
                        <Link
                            href={route('client.flows.keys.index')}
                            title="Encryption keys"
                            aria-label="Encryption keys"
                            className="ml-1 rounded-soft p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                        >
                            <KeyRound className="h-4 w-4" />
                        </Link>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        {/* Section E — replaces the old separate "Sync Meta Flows" (import) and "Sync Status" (refresh) buttons with one action that does both. */}
                        <Button variant="outline" onClick={syncFromMeta} disabled={syncingStatus}>
                            <RefreshCw className={'mr-1.5 h-4 w-4 ' + (syncingStatus ? 'animate-spin' : '')} /> {syncingStatus ? 'Syncing…' : 'Sync from Meta'}
                        </Button>
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="mr-1.5 h-4 w-4" /> New flow
                        </Button>
                    </div>
                </div>

                {flows.length > 0 && (
                    <div className="relative max-w-sm">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search flows by name or category…"
                            className="w-full rounded-soft border border-neutral-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                        />
                    </div>
                )}

                <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {filteredFlows.map((flow) => {
                        const status = displayStatus(flow);
                        const bannerTone = STATUS_BANNER_TONE[status.tone];
                        // min-h-[329px]: sized so the card itself, not just the menu, is the
                        // thing that's "big enough" — the menu portals from the trigger
                        // button's own getBoundingClientRect() (see CardActionsMenu), so its
                        // true offset from the card top is just padding-top (24px, p-6) + the
                        // title/trigger row's own height (28px) ≈ 52px, regardless of any
                        // sibling row's margin. + ~233px for the fully-expanded ⋮ menu (6
                        // items + a divider) + a ~44px buffer (comfortably covering the
                        // category row, status pill, and their margins below the trigger, so
                        // the floor holds even for a minimal-content card) = 329px. No
                        // Tailwind scale step lands within ~10px of 329 without either
                        // shaving that buffer down (min-h-80 = 320px) or overshooting it by
                        // 55px (min-h-96 = 384px), so this is an explicit bracket value
                        // rather than a named token. A FLOOR only — cards with more content
                        // (more fields, a wrapped category/status row, a wrapped
                        // validation-error banner) still grow taller than this via the
                        // surrounding flex/grid layout.
                        return (
                            <div key={flow.uuid} className="group relative flex min-h-[329px] flex-col overflow-hidden rounded-soft-lg border border-neutral-200 bg-white shadow-soft transition hover:border-brand-300 hover:shadow-soft-md dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-brand-700">
                                <div className="flex-1 p-6">
                                    <div className="mb-3 flex items-start justify-between gap-4">
                                        <Link href={route('client.flows.edit', flow.uuid)} className="block min-w-0 truncate text-xl font-bold uppercase text-neutral-900 hover:text-brand-600 dark:text-neutral-100">{flow.name}</Link>
                                        <CardActionsMenu isOpen={openMenu === flow.uuid} onOpenChange={(open) => setOpenMenu(open ? flow.uuid : null)} buttonLabel={'Actions for ' + flow.name}>
                                            <button type="button" onClick={() => { setOpenMenu(null); setInfoFlow(flow); }} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><InfoIcon className="h-4 w-4" /> Info</button>
                                            {flow.meta_sync_status === 'published' ? (
                                                // Section F/P — a Published flow's Builder is read-only (this is
                                                // the only edit path), so its menu drops Edit/Sync-draft/Publish
                                                // entirely rather than merely disabling them. Section P removes
                                                // "Sync from Meta" here too — redundant with the dashboard's own
                                                // top-header bulk action, which already refreshes every linked
                                                // flow including this one.
                                                <>
                                                    <Link href={route('client.flows.edit', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><Eye className="h-4 w-4" /> Preview</Link>
                                                    <button type="button" onClick={() => { setOpenMenu(null); setTestSendPhone(''); setTestSendState({ sending: false, error: null, success: null }); setTestSendFlow(flow); }} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><Send className="h-4 w-4" /> Send Test</button>
                                                    <Link href={route('client.flows.submissions', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><FileInput className="h-4 w-4" /> View submissions</Link>
                                                    <button type="button" onClick={(event) => runAction(event, flow, 'duplicate')} disabled={actioning !== null} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><Copy className="h-4 w-4" /> Duplicate</button>
                                                    <div className="my-1 border-t border-neutral-100 dark:border-neutral-800" />
                                                    <button type="button" onClick={() => { setOpenMenu(null); setDeleteConfirm(flow); }} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"><ShieldOff className="h-4 w-4" /> Deprecate</button>
                                                </>
                                            ) : (
                                                <>
                                                    <Link href={route('client.flows.edit', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><Pencil className="h-4 w-4" /> Edit</Link>
                                                    <button type="button" onClick={(event) => runAction(event, flow, 'sync')} disabled={actioning !== null} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><RefreshCw className="h-4 w-4" /> Sync Draft to Meta</button>
                                                    <button type="button" onClick={(event) => runAction(event, flow, 'publish-to-meta')} disabled={actioning !== null} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><Send className="h-4 w-4" /> Publish to Meta</button>
                                                    <Link href={route('client.flows.submissions', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><FileInput className="h-4 w-4" /> View submissions</Link>
                                                    <button type="button" onClick={(event) => runAction(event, flow, 'duplicate')} disabled={actioning !== null} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><Copy className="h-4 w-4" /> Duplicate</button>
                                                    <div className="my-1 border-t border-neutral-100 dark:border-neutral-800" />
                                                    <button type="button" onClick={() => { setOpenMenu(null); setDeleteConfirm(flow); }} className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"><Trash2 className="h-4 w-4" /> Delete</button>
                                                </>
                                            )}
                                        </CardActionsMenu>
                                    </div>

                                    <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                                        <LayoutGrid className="h-3.5 w-3.5" /> {flow.category ?? 'Uncategorized'}
                                    </div>

                                    <span className={'mt-3 inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ' + status.pillClass}>
                                        {status.pillLabel}
                                    </span>

                                    <div className="mt-5 grid grid-cols-2 gap-3">
                                        <Link href={route('client.flows.submissions', flow.uuid)} className="rounded-soft bg-neutral-50 px-4 py-3 hover:bg-neutral-100 dark:bg-neutral-800/50 dark:hover:bg-neutral-800">
                                            <p className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Submissions</p>
                                            <div className="mt-1.5 flex items-center gap-2">
                                                <ClipboardList className="h-4 w-4 text-neutral-400" />
                                                <span className="text-lg font-bold leading-none text-neutral-900 dark:text-neutral-100">{flow.submissions_count}</span>
                                            </div>
                                        </Link>
                                        <div className="rounded-soft bg-neutral-50 px-4 py-3 dark:bg-neutral-800/50">
                                            <p className="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Fields</p>
                                            <div className="mt-1.5 flex items-center gap-2">
                                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">{flow.field_count}</span>
                                                <span className="truncate text-sm font-semibold text-neutral-700 dark:text-neutral-300">Active Field{flow.field_count === 1 ? '' : 's'}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className={'mt-5 flex items-center gap-3 rounded-soft p-3 ' + bannerTone.box}>
                                        <div className={'flex h-9 w-9 shrink-0 items-center justify-center rounded-full ' + bannerTone.iconWrap}>
                                            <bannerTone.Icon className="h-4 w-4" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className={'truncate text-sm font-bold ' + bannerTone.headline}>{status.headline}</p>
                                            <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">{status.subtext}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {props.flash?.success && <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">{props.flash.success}</div>}
                {props.flash?.error && <div className="rounded-soft border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{props.flash.error}</div>}
                {flows.length === 0 && <EmptyState icon={<FileInput className="h-8 w-8" />} title="No flows yet" description="Create a static WhatsApp Flow to start collecting responses." action={{ label: 'New flow', onClick: () => setCreating(true) }} />}
                {flows.length > 0 && filteredFlows.length === 0 && <p className="py-8 text-center text-sm text-neutral-500">No flows match &ldquo;{search}&rdquo;.</p>}
            </div>

            {creating && (
                <Modal show onClose={() => setCreating(false)}>
                    <Modal.Header title="New WhatsApp Flow" onClose={() => setCreating(false)} />
                    <form onSubmit={create}>
                        <Modal.Body className="space-y-4">
                            <label className="block text-sm font-medium">Name
                                <input autoFocus value={name} onChange={(e) => setName(e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                            </label>
                            {createErrors.name && <p className="text-sm text-red-600">{createErrors.name}</p>}
                            <label className="block text-sm font-medium">Category
                                <select value={category} onChange={(e) => setCategory(e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                                </select>
                            </label>
                        </Modal.Body>
                        <Modal.Footer>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>Cancel</Button>
                            <Button type="submit" disabled={createProcessing}><Plus className="mr-1.5 h-4 w-4" /> Create and build</Button>
                        </Modal.Footer>
                    </form>
                </Modal>
            )}

            {/* Section G — a materially different, stronger dialog for the
                irreversible Deprecate path than for a routine Delete: a
                Published Flow's row and submission history are NOT removed
                (only Meta-side usability ends), so the copy says so plainly
                and never claims the Flow will be "removed". */}
            {isDeprecateConfirm ? (
                <ConfirmDestructiveModal
                    show={!!deleteConfirm}
                    onClose={() => setDeleteConfirm(null)}
                    onConfirm={deleteFlow}
                    title="Deprecate this published Flow?"
                    body={`${deleteConfirm?.name ?? 'This Flow'} is live and published on Meta. Deprecating it is PERMANENT and cannot be undone — WhatsApp will stop accepting it, and Meta does not offer any way to un-deprecate it. Its record and past submissions are kept, only its Meta-side usability ends. Type DEPRECATE to continue.`}
                    confirmWord="DEPRECATE"
                    confirmLabel="Deprecate"
                />
            ) : (
                <ConfirmDestructiveModal show={!!deleteConfirm} onClose={() => setDeleteConfirm(null)} onConfirm={deleteFlow} title="Delete WhatsApp Flow?" body={'This permanently removes ' + (deleteConfirm?.name ?? 'this Flow') + ' and its local definition. Type DELETE to continue.'} />
            )}

            {/* Section F/E — "Send Test" from a Published card's ⋮ menu.
                Same endpoint and plausibility gate as Builder.jsx's own
                modal; duplicated rather than shared because Builder's
                version is scoped to `flow` from props, not a menu selection. */}
            {testSendFlow && (
                <Modal show onClose={() => setTestSendFlow(null)} maxWidth="sm">
                    <Modal.Header title="Send" onClose={() => setTestSendFlow(null)} />
                    <form onSubmit={submitTestSend}>
                        <Modal.Body className="space-y-4">
                            <div className="rounded-soft border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-700 dark:bg-neutral-800/50">
                                <p className="font-medium text-neutral-900 dark:text-neutral-100">{testSendFlow.name}</p>
                                <p className="text-xs text-neutral-500">Flow ID: {testSendFlow.meta_flow_id}</p>
                            </div>
                            <div>
                                <label htmlFor="index_test_send_phone" className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">Phone Number</label>
                                <div className="relative">
                                    <Phone className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                    <input
                                        id="index_test_send_phone"
                                        autoFocus
                                        value={testSendPhone}
                                        onChange={(e) => setTestSendPhone(e.target.value)}
                                        placeholder="e.g. 919690309316"
                                        className="w-full rounded-soft border border-neutral-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                    />
                                </div>
                                <p className="mt-1.5 text-xs text-neutral-500">Enter number with country code, no + or spaces</p>
                            </div>
                            {testSendState.error && <p className="rounded-soft border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{testSendState.error}</p>}
                            {testSendState.success && <p className="rounded-soft border border-emerald-200 bg-emerald-50 p-2 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">{testSendState.success}</p>}
                        </Modal.Body>
                        <Modal.Footer>
                            <Button type="button" variant="ghost" onClick={() => setTestSendFlow(null)}>Cancel</Button>
                            <Button type="submit" disabled={testSendPhone.replace(/\D/g, '').length < 8 || testSendState.sending}>{testSendState.sending ? 'Sending…' : 'Send Now'}</Button>
                        </Modal.Footer>
                    </form>
                </Modal>
            )}

            {/* Task 2 — the read-only Info panel. Edit already exists separately for actual editing. */}
            {infoFlow && (
                <Modal show onClose={() => setInfoFlow(null)} maxWidth="2xl">
                    <Modal.Header title={infoFlow.name} subtitle="Quick-glance details" onClose={() => setInfoFlow(null)} />
                    <Modal.Body>
                        <Tabs tabs={[
                            { key: 'overview', label: 'Overview & Settings', content: <FlowOverviewPanel flow={infoFlow} webFormUrl={webFormUrl(infoFlow)} /> },
                            { key: 'fields', label: 'Form Fields', content: <FlowFieldsPanel flow={infoFlow} /> },
                        ]} />
                    </Modal.Body>
                </Modal>
            )}
        </ClientLayout>
    );
}

/**
 * Task 2 — the card's ⋮ menu, portal-rendered to `document.body`.
 *
 * Root cause of the clipping bug: the card container has `overflow-hidden`
 * (so the bottom status banner's flat edge respects the card's rounded
 * corners without needing its own `rounded-b-*` class — there is no other
 * reason for it on this card). The old menu rendered as a plain
 * `position: absolute` descendant of that same card, so once the menu grew
 * past 5 items it was silently clipped by an ANCESTOR's overflow, not by
 * anything about the menu's own position.
 *
 * `Components/ui/Dropdown.jsx` (this codebase's shared dropdown primitive)
 * was checked first, per the standard fix for this class of bug — it does
 * NOT render via a Portal either (same `position: absolute` inside a
 * `position: relative` wrapper), so adopting it would not have fixed
 * anything, and Index.jsx does not use it today. Rather than teach the
 * shared primitive to portal (a wider change needing re-verification at
 * every other call site) or strip `overflow-hidden` from the card (which
 * would un-clip the banner's corners — a real visual regression), this
 * portals JUST this menu to `document.body`, positioned from the trigger
 * button's own `getBoundingClientRect()`. That sidesteps the ancestor's
 * overflow entirely, with no change to the card's other visual behaviour.
 *
 * `position: fixed` does not track page scroll the way the old in-flow
 * `absolute` menu implicitly did, so the menu closes on scroll/resize
 * rather than drifting away from its trigger.
 *
 * ─── Vertical flip ───────────────────────────────────────────────────────
 *
 * Horizontal placement (left/right-edge anchoring) can use a fixed
 * MENU_WIDTH because the menu's width IS fixed — `w-52` never varies.
 * Height is a different claim: every card's menu happens to render the same
 * 6 items + 1 divider today, but a fixed height constant would silently
 * drift out of sync the day a 7th item is added, where a fixed WIDTH
 * constant would not (nothing here changes the menu's width). So height is
 * MEASURED after mount, via `menuRef` + `getBoundingClientRect()`, rather
 * than estimated — this is a two-pass layout: the menu first mounts with a
 * downward guess (needed to have a real DOM node to measure at all), then
 * `useLayoutEffect` measures it and flips upward if there isn't room below,
 * all before the browser paints, so there's no visible flicker.
 */
function CardActionsMenu({ isOpen, onOpenChange, buttonLabel, children }) {
    const triggerRef = useRef(null);
    const menuRef = useRef(null);
    const [geometry, setGeometry] = useState(null); // { left, triggerTop, triggerBottom }
    const [menuHeight, setMenuHeight] = useState(null);
    const MENU_WIDTH = 208; // w-52
    const VIEWPORT_MARGIN = 8;

    useEffect(() => {
        if (!isOpen || !triggerRef.current) {
            setGeometry(null);
            setMenuHeight(null);
            return undefined;
        }

        const rect = triggerRef.current.getBoundingClientRect();
        // Right-edge anchored to the trigger (menu grows leftward from the
        // button, staying near its own card) — EXCEPT when that would push
        // the menu's left edge past the viewport's left edge (a narrow
        // screen, or a trigger near the left edge of the layout), in which
        // case it falls back to left-anchoring to the trigger instead, so
        // the menu grows rightward into the room that IS there.
        const rightAnchored = rect.right - MENU_WIDTH;
        const left = rightAnchored < VIEWPORT_MARGIN ? rect.left : rightAnchored;
        setGeometry({ left, triggerTop: rect.top, triggerBottom: rect.bottom });
        setMenuHeight(null); // re-measured below, on this same opening

        const close = () => onOpenChange(false);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        return () => {
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen]);

    // Pass 2 — the menu now exists in the DOM (mounted below with the
    // downward guess), so its real height can be measured. Runs before
    // paint: correcting `menuHeight` here never produces a visible jump.
    useLayoutEffect(() => {
        if (!isOpen || !geometry || !menuRef.current) return;
        setMenuHeight(menuRef.current.getBoundingClientRect().height);
    }, [isOpen, geometry]);

    const spaceBelow = geometry ? window.innerHeight - geometry.triggerBottom : 0;
    const shouldFlip = geometry && menuHeight !== null && menuHeight + VIEWPORT_MARGIN > spaceBelow;
    const top = geometry && (shouldFlip
        ? geometry.triggerTop - (menuHeight ?? 0) - 4
        : geometry.triggerBottom + 4);

    return (
        <div className="relative shrink-0">
            <button ref={triggerRef} type="button" aria-label={buttonLabel} onClick={() => onOpenChange(!isOpen)} className="rounded p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-800 dark:hover:bg-neutral-800 dark:hover:text-neutral-100">
                <MoreVertical className="h-5 w-5" />
            </button>
            {isOpen && geometry && createPortal(
                <div
                    ref={menuRef}
                    data-testid="card-actions-menu"
                    style={{ position: 'fixed', top, left: geometry.left }}
                    className="z-40 w-52 overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 shadow-soft-xl dark:border-neutral-700 dark:bg-neutral-900"
                >
                    {children}
                </div>,
                document.body,
            )}
        </div>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="flex items-start justify-between gap-4 py-1.5 text-sm">
            <span className="text-neutral-500">{label}</span>
            <span className="text-right font-medium text-neutral-900 dark:text-neutral-100">{value}</span>
        </div>
    );
}

function FlowOverviewPanel({ flow, webFormUrl }) {
    return (
        <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
            <InfoRow label="Category" value={flow.category ?? 'Uncategorized'} />
            <InfoRow label="Total steps" value={flow.step_count} />
            <InfoRow label="Total fields" value={flow.field_count} />
            <InfoRow label="reCAPTCHA required" value={flow.recaptcha_enabled ? 'Yes' : 'No'} />
            <InfoRow label="Web form" value={flow.web_form_enabled
                ? <a href={webFormUrl} target="_blank" rel="noreferrer" className="text-brand-600 hover:underline">{webFormUrl}</a>
                : 'Disabled'} />
            <InfoRow label="Submit button text" value={flow.submit_settings?.button_text || '—'} />
            <InfoRow label="Success message" value={flow.submit_settings?.success_message || '—'} />
            <InfoRow label="Meta Flow ID" value={flow.meta_flow_id ?? 'Not synced'} />
            <InfoRow label="Meta sync status" value={META_LABELS[flow.meta_sync_status] ?? 'Not synced'} />
            <InfoRow label="Submissions" value={flow.submissions_count} />
            <InfoRow label="Created on" value={flow.created_at ? new Date(flow.created_at).toLocaleDateString() : '—'} />
            <InfoRow label="Last updated" value={flow.updated_at ? new Date(flow.updated_at).toLocaleDateString() : '—'} />
            <RawFlowJsonSection flow={flow} />
        </div>
    );
}

/**
 * Section D — the compiled Meta Flow JSON, previously a permanently-visible
 * block on the Builder page underneath "Preview Flow JSON". That button now
 * opens the live phone-mockup preview instead (the primary preview
 * experience); this raw view still has real debugging value (comparing
 * exactly what Meta receives), so it moves here as a collapsed, secondary
 * section rather than being removed — collapsed by default, and fetched
 * lazily on first expand rather than on every Info-modal open.
 */
function RawFlowJsonSection({ flow }) {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState({ loading: false, error: null, json: null });

    const toggle = async () => {
        const next = !open;
        setOpen(next);
        if (next && state.json === null && !state.loading) {
            setState({ loading: true, error: null, json: null });
            try {
                const response = await fetch(route('client.flows.preview', flow.uuid), { headers: { Accept: 'application/json' } });
                const body = await response.json();
                if (!response.ok) throw new Error(body.message ?? 'Flow JSON could not be compiled.');
                setState({ loading: false, error: null, json: body });
            } catch (error) {
                setState({ loading: false, error: error.message, json: null });
            }
        }
    };

    return (
        <div className="py-1.5">
            <button type="button" onClick={toggle} aria-expanded={open} className="flex w-full items-center justify-between gap-2 py-1 text-left text-sm font-medium text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100">
                Raw compiled Meta Flow JSON
                <ChevronDown className={'h-4 w-4 shrink-0 transition-transform ' + (open ? 'rotate-180' : '')} />
            </button>
            {open && (
                <div className="mt-2">
                    {state.loading && <p className="text-sm text-neutral-500">Loading…</p>}
                    {state.error && <p className="rounded-soft border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{state.error}</p>}
                    {state.json && <pre className="max-h-80 overflow-auto rounded-soft bg-neutral-50 p-3 text-xs text-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">{JSON.stringify(state.json, null, 2)}</pre>}
                </div>
            )}
        </div>
    );
}

/**
 * There is deliberately no "default value" column here — this codebase's
 * field shape (id/type/label/name/required/helper_text/options/step/order,
 * see WhatsappFlow's screens docblock) has never tracked one. Inventing a
 * column for data that does not exist would be worse than omitting it.
 */
function FlowFieldsPanel({ flow }) {
    return (
        <div className="space-y-3">
            {(flow.screens ?? []).map((step, stepIndex) => (
                <div key={step.id} className="rounded-soft border border-neutral-200 p-3 dark:border-neutral-700">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">Step {stepIndex + 1}: {step.title}</p>
                    <div className="space-y-2">
                        {step.fields.map((field) => (
                            <div key={field.id} className="flex flex-wrap items-center justify-between gap-2 rounded-soft bg-neutral-50 px-3 py-2 text-sm dark:bg-neutral-800/50">
                                <div>
                                    <span className="font-medium text-neutral-900 dark:text-neutral-100">{field.label}</span>
                                    {field.name && <span className="ml-2 text-xs text-neutral-400">({field.name})</span>}
                                    {field.helper_text && <p className="text-xs text-neutral-500">{field.helper_text}</p>}
                                    {field.options?.length > 0 && <p className="text-xs text-neutral-500">Options: {field.options.map((o) => typeof o === 'string' ? o : o.title).join(', ')}</p>}
                                </div>
                                <div className="flex shrink-0 items-center gap-1.5">
                                    <Badge size="sm">{field.type}</Badge>
                                    {field.required && <Badge size="sm" variant="brand">Required</Badge>}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
