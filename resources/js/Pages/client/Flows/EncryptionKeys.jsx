import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, KeyRound, RotateCw, Upload, XCircle } from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';

const uploadLabel = (pair) => {
    if (!pair) return 'Not generated';
    return ({ not_uploaded: 'Generated — not uploaded', uploaded: 'Uploaded to Meta', upload_failed: 'Upload failed' }[pair.meta_upload_status] ?? pair.meta_upload_status);
};

const uploadTone = (pair) => {
    if (!pair) return 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    return ({ not_uploaded: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', uploaded: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', upload_failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' }[pair.meta_upload_status] ?? 'bg-neutral-100 text-neutral-700');
};

export default function FlowEncryptionKeys({ phones }) {
    const { props } = usePage();
    const [acting, setActing] = useState(null);
    const action = (phone, name) => {
        setActing(`${name}-${phone.id}`);
        router.post(route(`client.flows.keys.${name}`, phone.id), {}, { preserveScroll: true, onFinish: () => setActing(null) });
    };

    return <ClientLayout title="Flow encryption keys">
        <Head title="Flow encryption keys" />
        <div className="space-y-6">
            <div><Link href={route('client.flows.index')} className="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-brand-600"><ArrowLeft className="h-4 w-4" /> All flows</Link><h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">Flow endpoint encryption</h2><p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Each WhatsApp business phone number has its own platform-generated RSA key pair.</p></div>
            {props.flash?.success && <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{props.flash.success}</div>}
            {props.flash?.error && <div className="rounded-soft border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{props.flash.error}</div>}
            <div className="space-y-3">{phones.map((phone) => { const pair = phone.key_pair; return <section key={phone.id} className="rounded-soft-lg border border-neutral-200 bg-white p-5 shadow-soft dark:border-neutral-800 dark:bg-neutral-900"><div className="flex flex-wrap items-start justify-between gap-4"><div className="flex gap-3"><div className="rounded-soft bg-brand-50 p-2 dark:bg-brand-900/20"><KeyRound className="h-5 w-5 text-brand-600" /></div><div><h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{phone.display_phone ?? phone.phone_number_id}</h3><p className="text-xs text-neutral-500">{phone.verified_name ?? 'WhatsApp business phone'} · {phone.waba_id}</p><p className="mt-2 text-xs text-neutral-500">{pair ? `Key version ${pair.key_version}` : 'No encryption key generated yet'}</p></div></div><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${uploadTone(pair)}`}>{pair?.meta_upload_status === 'uploaded' ? <CheckCircle2 className="h-3.5 w-3.5" /> : pair?.meta_upload_status === 'upload_failed' ? <XCircle className="h-3.5 w-3.5" /> : <KeyRound className="h-3.5 w-3.5" />}{uploadLabel(pair)}</span></div>{pair?.meta_upload_error && <p className="mt-3 text-sm text-red-600 dark:text-red-300">{pair.meta_upload_error}</p>}<div className="mt-4 flex flex-wrap gap-2"><button disabled={acting !== null} onClick={() => action(phone, 'generate')} className="rounded-lg border border-brand-300 px-3 py-2 text-sm font-medium text-brand-700 disabled:opacity-50 dark:border-brand-700 dark:text-brand-300">{acting === `generate-${phone.id}` ? 'Generating…' : 'Generate'}</button><button disabled={acting !== null || !pair || pair.meta_upload_status === 'uploaded'} onClick={() => action(phone, 'upload')} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"><Upload className="h-4 w-4" /> {acting === `upload-${phone.id}` ? 'Uploading…' : 'Upload to Meta'}</button><button disabled={acting !== null || !pair} onClick={() => action(phone, 'rotate')} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 disabled:opacity-50 dark:border-neutral-600 dark:text-neutral-200"><RotateCw className="h-4 w-4" /> {acting === `rotate-${phone.id}` ? 'Rotating…' : 'Rotate'}</button></div></section>; })}</div>
            {phones.length === 0 && <EmptyState icon={<KeyRound className="h-8 w-8" />} title="No WhatsApp phone numbers" description="Connect a WhatsApp Business Account and phone number before configuring Flow endpoint encryption." />}
        </div>
    </ClientLayout>;
}
