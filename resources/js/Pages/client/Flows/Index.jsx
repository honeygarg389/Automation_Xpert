import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle, ClipboardList, Download, FileInput, Info as InfoIcon, KeyRound, LayoutGrid,
    MoreVertical, Pencil, Plus, RefreshCw, Rocket, Search, Send, Trash2,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { Badge, Button, Checkbox, ConfirmDestructiveModal, Modal, Tabs } from '@/Components/ui';

const META_LABELS = { syncing: 'Syncing', synced_draft: 'Synced draft', published: 'Published', failed: 'Sync failed' };

// The card's own status pill shows flow.status (this workspace's authored
// draft/published state) — a plain 2-value enum, distinct from the richer
// meta_sync_status shown in the Info modal and in the card's bottom banner.
const STATUS_PILL_CLASSES = {
    published: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    draft: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
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
    const [importState, setImportState] = useState(null); // null | { loading, error, candidates, selected, submitting }
    const [name, setName] = useState('');
    const [category, setCategory] = useState('OTHER');
    const [createErrors, setCreateErrors] = useState({});
    const [createProcessing, setCreateProcessing] = useState(false);

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

    // "Sync Status" — the bulk RECONCILE action. Only ever touches flows this
    // workspace already links (meta_flow_id set): pulls each one's content
    // down from Meta, overwriting local screens. Not the same thing as the
    // Import picker below, which brings in flows we don't have yet.
    const syncStatus = () => {
        if (!window.confirm('Sync Status will overwrite saved local screens for every already-linked flow with the current Meta Flow JSON. Continue?')) return;
        setSyncingStatus(true);
        router.post(route('client.flows.sync-status'), {}, { preserveScroll: true, onFinish: () => setSyncingStatus(false) });
    };

    // "Sync Meta Flows" — the IMPORT picker. Finds Meta flows with no local
    // record and lets the owner bring selected ones in as new local flows.
    const openImportPicker = async () => {
        setImportState({ loading: true, error: null, candidates: [], selected: [], submitting: false });
        try {
            const response = await fetch(route('client.flows.import.picker'), { headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message ?? 'Meta Flows could not be listed.');
            setImportState({ loading: false, error: null, candidates: body.flows, selected: [], submitting: false });
        } catch (error) {
            setImportState({ loading: false, error: error.message, candidates: [], selected: [], submitting: false });
        }
    };
    const toggleImportSelection = (metaFlowId) => {
        setImportState((state) => ({
            ...state,
            selected: state.selected.includes(metaFlowId)
                ? state.selected.filter((id) => id !== metaFlowId)
                : [...state.selected, metaFlowId],
        }));
    };
    const submitImport = () => {
        if (!importState || importState.selected.length === 0) return;
        setImportState((state) => ({ ...state, submitting: true }));
        router.post(route('client.flows.import.store'), { meta_flow_ids: importState.selected }, {
            preserveScroll: true,
            onSuccess: () => setImportState(null),
            onFinish: () => setImportState((state) => state && ({ ...state, submitting: false })),
        });
    };

    const deleteFlow = () => {
        if (!deleteConfirm) return;
        router.delete(route('client.flows.destroy', deleteConfirm.uuid), {
            preserveScroll: true,
            onSuccess: () => setDeleteConfirm(null),
        });
    };

    const webFormUrl = (flow) => (flow.public_slug ? route('public.flows.form.show', flow.public_slug) : null);

    const statusBanner = (flow) => {
        if (flow.meta_sync_error) {
            return { tone: 'danger', headline: 'Sync Error', subtext: flow.meta_sync_error };
        }
        if (flow.meta_validation_errors?.length > 0) {
            return { tone: 'danger', headline: 'Validation Errors', subtext: `${flow.meta_validation_errors.length} validation error${flow.meta_validation_errors.length === 1 ? '' : 's'} from Meta.` };
        }
        if (flow.meta_sync_status === 'published') {
            return { tone: 'success', headline: 'Published to Meta', subtext: 'Syncing with WhatsApp Flows' };
        }
        return { tone: 'neutral', headline: 'Draft Status', subtext: 'Not yet live on Meta platform' };
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
                        <Button variant="outline" onClick={openImportPicker}>
                            <Download className="mr-1.5 h-4 w-4" /> Sync Meta Flows
                        </Button>
                        <Button variant="outline" onClick={syncStatus} disabled={syncingStatus}>
                            <RefreshCw className={'mr-1.5 h-4 w-4 ' + (syncingStatus ? 'animate-spin' : '')} /> {syncingStatus ? 'Syncing…' : 'Sync Status'}
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
                        const banner = statusBanner(flow);
                        const bannerTone = STATUS_BANNER_TONE[banner.tone];
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
                                            <button type="button" onClick={() => { setOpenMenu(null); setInfoFlow(flow); }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><InfoIcon className="h-4 w-4" /> Info</button>
                                            <Link href={route('client.flows.edit', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><Pencil className="h-4 w-4" /> Edit</Link>
                                            <button type="button" onClick={(event) => runAction(event, flow, 'sync')} disabled={actioning !== null || flow.meta_sync_status === 'published'} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><RefreshCw className="h-4 w-4" /> Sync to Meta</button>
                                            <button type="button" onClick={(event) => runAction(event, flow, 'publish')} disabled={actioning !== null || !flow.meta_flow_id || flow.meta_sync_status === 'published'} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-neutral-50 disabled:opacity-50 dark:hover:bg-neutral-800"><Send className="h-4 w-4" /> Publish</button>
                                            <Link href={route('client.flows.submissions', flow.uuid)} onClick={() => setOpenMenu(null)} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800"><FileInput className="h-4 w-4" /> View submissions</Link>
                                            <div className="my-1 border-t border-neutral-100 dark:border-neutral-800" />
                                            <button type="button" onClick={() => { setOpenMenu(null); setDeleteConfirm(flow); }} className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"><Trash2 className="h-4 w-4" /> Delete</button>
                                        </CardActionsMenu>
                                    </div>

                                    <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-neutral-400 dark:text-neutral-500">
                                        <LayoutGrid className="h-3.5 w-3.5" /> {flow.category ?? 'Uncategorized'}
                                    </div>

                                    <span className={'mt-3 inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide ' + (STATUS_PILL_CLASSES[flow.status] ?? STATUS_PILL_CLASSES.draft)}>
                                        {flow.status}
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
                                            <p className={'truncate text-sm font-bold ' + bannerTone.headline}>{banner.headline}</p>
                                            <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">{banner.subtext}</p>
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

            <ConfirmDestructiveModal show={!!deleteConfirm} onClose={() => setDeleteConfirm(null)} onConfirm={deleteFlow} title="Delete WhatsApp Flow?" body={'This permanently removes ' + (deleteConfirm?.name ?? 'this Flow') + ' and its local definition. Type DELETE to continue.'} />

            {/* Task 1 — the import picker, now correctly named "Sync Meta Flows". */}
            {importState && (
                <Modal show onClose={() => setImportState(null)}>
                    <Modal.Header title="Sync Meta Flows" subtitle="Import Flows that exist on Meta but have no local record yet." onClose={() => setImportState(null)} />
                    <Modal.Body>
                        <div className="mb-3 flex items-center justify-between">
                            <p className="text-xs text-neutral-500">Already-linked Flows are not shown — use Sync Status to refresh those.</p>
                            <button type="button" onClick={openImportPicker} disabled={importState.loading} title="Refresh" className="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800">
                                <RefreshCw className={'h-4 w-4 ' + (importState.loading ? 'animate-spin' : '')} />
                            </button>
                        </div>
                        {importState.loading && <p className="py-6 text-center text-sm text-neutral-500">Loading Meta Flows…</p>}
                        {importState.error && <p className="rounded-soft border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300">{importState.error}</p>}
                        {!importState.loading && !importState.error && importState.candidates.length === 0 && (
                            <p className="py-6 text-center text-sm text-neutral-500">No importable Flows — every Flow on Meta is already linked here.</p>
                        )}
                        {!importState.loading && importState.candidates.length > 0 && (
                            <ul className="max-h-80 space-y-1 overflow-y-auto">
                                {importState.candidates.map((candidate) => (
                                    <li key={candidate.meta_flow_id} className="flex items-center justify-between gap-3 rounded-soft border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                                        <Checkbox
                                            id={'import-' + candidate.meta_flow_id}
                                            checked={importState.selected.includes(candidate.meta_flow_id)}
                                            onChange={() => toggleImportSelection(candidate.meta_flow_id)}
                                            label={candidate.name}
                                        />
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            {candidate.categories.map((c) => <Badge key={c} size="sm">{c}</Badge>)}
                                            <Badge size="sm" variant={candidate.status === 'PUBLISHED' ? 'success' : 'default'}>{candidate.status}</Badge>
                                            {candidate.validation_errors.length > 0 && <Badge size="sm" variant="danger">{candidate.validation_errors.length} error{candidate.validation_errors.length === 1 ? '' : 's'}</Badge>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Modal.Body>
                    <Modal.Footer>
                        <Button variant="ghost" onClick={() => setImportState(null)}>Cancel</Button>
                        <Button onClick={submitImport} disabled={importState.selected.length === 0 || importState.submitting}>
                            <Download className="mr-1.5 h-4 w-4" /> {importState.submitting ? 'Importing…' : `Import${importState.selected.length ? ' (' + importState.selected.length + ')' : ''}`}
                        </Button>
                    </Modal.Footer>
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
