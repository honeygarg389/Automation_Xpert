import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FileInput, KeyRound, Plus, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';

export default function FlowsIndex({ flows, categories }) {
    const [creating, setCreating] = useState(false);
    const [actioning, setActioning] = useState(null);
    const { props } = usePage();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '', description: '', category: 'OTHER', status: 'draft', submit_settings: {},
    });

    const create = (event) => {
        event.preventDefault();
        post(route('client.flows.store'), { onSuccess: () => { reset(); setCreating(false); } });
    };
    const metaLabel = (flow) => ({ syncing: 'Syncing', synced_draft: 'Synced draft', published: 'Published', failed: 'Sync failed' }[flow.meta_sync_status] ?? 'Not synced');
    const metaTone = (flow) => ({ syncing: 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300', synced_draft: 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300', published: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' }[flow.meta_sync_status] ?? 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300');
    const runAction = (event, flow, action) => {
        event.preventDefault(); event.stopPropagation();
        setActioning(`${action}-${flow.uuid}`);
        router.post(route(`client.flows.${action}`, flow.uuid), {}, { preserveScroll: true, onFinish: () => setActioning(null) });
    };

    return (
        <ClientLayout title="WhatsApp Flows">
            <Head title="WhatsApp Flows" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">WhatsApp Flows</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">Build static, multi-step forms for WhatsApp.</p>
                    </div>
                    <div className="flex shrink-0 gap-2"><Link href={route('client.flows.keys.index')} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:border-brand-400 hover:text-brand-700 dark:border-neutral-600 dark:text-neutral-200"><KeyRound className="h-4 w-4" /> Encryption keys</Link><button onClick={() => setCreating(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-brand-700"><Plus className="h-4 w-4" /> New flow</button></div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {flows.map((flow) => (
                        <div key={flow.uuid} className="group rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft transition hover:border-brand-300 hover:shadow-soft-md dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-brand-700">
                            <div className="mb-4 flex items-start justify-between gap-3">
                                <div className="rounded-soft bg-brand-50 p-2 dark:bg-brand-900/20"><FileInput className="h-5 w-5 text-brand-600 dark:text-brand-400" /></div>
                                <div className="flex gap-1.5">
                                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">{flow.category ?? 'Uncategorized'}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${metaTone(flow)}`}>{metaLabel(flow)}</span>
                                </div>
                            </div>
                            <Link href={route('client.flows.edit', flow.uuid)} className="block truncate font-semibold text-neutral-900 hover:text-brand-600 dark:text-neutral-100">{flow.name}</Link>
                            <p className="mt-2 flex items-center gap-1.5 text-sm text-neutral-500 dark:text-neutral-400"><SlidersHorizontal className="h-4 w-4" /> {flow.field_count} field{flow.field_count === 1 ? '' : 's'}</p>
                            {flow.meta_sync_error && <p className="mt-3 text-xs text-red-600 dark:text-red-300">{flow.meta_sync_error}</p>}
                            <div className="mt-4 flex gap-2"><button type="button" disabled={actioning !== null || flow.meta_sync_status === 'published'} onClick={(event) => runAction(event, flow, 'sync')} className="rounded-lg border border-brand-300 px-2.5 py-1.5 text-xs font-medium text-brand-700 disabled:opacity-50 dark:border-brand-700 dark:text-brand-300">{actioning === `sync-${flow.uuid}` ? 'Syncing…' : 'Sync to Meta'}</button><button type="button" disabled={actioning !== null || !flow.meta_flow_id || flow.meta_sync_status === 'published'} onClick={(event) => runAction(event, flow, 'publish')} className="rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-medium text-white disabled:opacity-50">{actioning === `publish-${flow.uuid}` ? 'Publishing…' : 'Publish'}</button></div>
                        </div>
                    ))}
                </div>

                {props.flash?.success && <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{props.flash.success}</div>}
                {props.flash?.error && <div className="rounded-soft border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{props.flash.error}</div>}

                {flows.length === 0 && <EmptyState icon={<FileInput className="h-8 w-8" />} title="No flows yet" description="Create a static WhatsApp Flow to start collecting responses." action={{ label: 'New flow', onClick: () => setCreating(true) }} />}
            </div>

            {creating && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                <form onSubmit={create} className="w-full max-w-md space-y-4 rounded-soft-lg border border-neutral-200 bg-white p-6 shadow-soft-xl dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center justify-between"><h3 className="font-semibold">New WhatsApp Flow</h3><button type="button" onClick={() => setCreating(false)} className="text-sm text-neutral-500">Cancel</button></div>
                    <label className="block text-sm font-medium">Name<input autoFocus value={data.name} onChange={(e) => setData('name', e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" /></label>
                    {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    <label className="block text-sm font-medium">Category<select value={data.category} onChange={(e) => setData('category', e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">{categories.map((category) => <option key={category} value={category}>{category}</option>)}</select></label>
                    <button disabled={processing} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"><Plus className="h-4 w-4" /> Create and build</button>
                </form>
            </div>}
        </ClientLayout>
    );
}
