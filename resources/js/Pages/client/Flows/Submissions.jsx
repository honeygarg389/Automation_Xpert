import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Search } from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Modal } from '@/Components/ui';

const answerValue = (value) => Array.isArray(value) ? value.join(', ') : (typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? ''));

export default function FlowSubmissions({ flow, submissions, filters }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [detail, setDetail] = useState(null);
    const submitSearch = (event) => {
        event.preventDefault();
        router.get(route('client.flows.submissions', flow.uuid), { search }, { preserveState: true, preserveScroll: true });
    };

    return (
        <ClientLayout title={'Submissions · '+flow.name}>
            <Head title={'Submissions · '+flow.name} />
            <div className="space-y-6">
                <div>
                    <Link href={route('client.flows.index')} className="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-brand-600"><ArrowLeft className="h-4 w-4" /> All flows</Link>
                    <div className="mt-2 flex flex-wrap items-end justify-between gap-3">
                        <div><h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{flow.name} submissions</h2><p className="mt-0.5 text-sm text-neutral-500">Completed WhatsApp Flow responses, newest first.</p></div>
                        <Link href={route('client.flows.edit', flow.uuid)} className="text-sm font-medium text-brand-600 hover:text-brand-700">Edit flow</Link>
                    </div>
                </div>

                <form onSubmit={submitSearch} className="flex max-w-md gap-2">
                    <label className="sr-only" htmlFor="submission-search">Search submissions</label>
                    <input id="submission-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search linked contact" className="min-w-0 flex-1 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-900" />
                    <button className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:border-brand-400 dark:border-neutral-600"><Search className="h-4 w-4" /> Search</button>
                </form>

                <div className="overflow-hidden rounded-soft-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead className="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-950">
                                <tr><th className="px-4 py-3 font-medium">Contact</th><th className="px-4 py-3 font-medium">Answers</th><th className="px-4 py-3 font-medium">Submitted</th><th className="px-4 py-3" /></tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {submissions.data.map((submission) => <tr key={submission.uuid}>
                                    <td className="px-4 py-3"><div className="font-medium text-neutral-900 dark:text-neutral-100">{submission.contact?.name || 'Unidentified'}</div><div className="text-xs text-neutral-500">{submission.contact?.phone || submission.contact?.email || 'No linked contact'}</div></td>
                                    <td className="max-w-md px-4 py-3 text-neutral-600 dark:text-neutral-300"><span className="line-clamp-2">{submission.answer_preview || 'No answers provided'}</span></td>
                                    <td className="px-4 py-3 text-neutral-500">{submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '—'}</td>
                                    <td className="px-4 py-3 text-right"><button type="button" onClick={() => setDetail(submission)} className="text-sm font-medium text-brand-600 hover:text-brand-700">View answers</button></td>
                                </tr>)}
                                {submissions.data.length === 0 && <tr><td colSpan="4" className="px-4 py-10 text-center text-neutral-500">No submissions match this search.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>

                {submissions.links?.length > 3 && <div className="flex flex-wrap gap-1">{submissions.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => link.url && router.visit(link.url)} className={'rounded px-3 py-1.5 text-sm '+(link.active ? 'bg-brand-600 text-white' : 'border border-neutral-200 text-neutral-600 disabled:opacity-40 dark:border-neutral-700')} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
            </div>

            <Modal show={!!detail} onClose={() => setDetail(null)} maxWidth="lg">
                <Modal.Header title="Submission answers" onClose={() => setDetail(null)} />
                <Modal.Body>
                    <dl className="space-y-3">{Object.entries(detail?.answers ?? {}).map(([key, value]) => <div key={key} className="grid gap-1 border-b border-neutral-100 pb-3 last:border-0 dark:border-neutral-800 sm:grid-cols-3"><dt className="font-medium text-neutral-700 dark:text-neutral-200">{key}</dt><dd className="break-words text-neutral-600 dark:text-neutral-300 sm:col-span-2">{answerValue(value)}</dd></div>)}</dl>
                </Modal.Body>
            </Modal>
        </ClientLayout>
    );
}
