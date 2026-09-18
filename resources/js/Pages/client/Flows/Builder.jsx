import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronDown, ChevronUp, Copy, Eye, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';

const blankField = (step, order) => ({ id: `field_${Date.now()}_${order}`, type: 'text', label: 'New field', name: `field_${step}_${order}`, required: false, helper_text: '', options: [], step, order });
const blankStep = (number) => ({ id: `step_${number}`, title: `Step ${number}`, fields: [blankField(number, 1)] });

export default function FlowsBuilder({ flow, categories, fieldTypes }) {
    const { props } = usePage();
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState(null);
    const { data, setData, put, processing, errors } = useForm({
        name: flow.name, description: flow.description ?? '', category: flow.category ?? 'OTHER', status: flow.status,
        screens: flow.screens ?? [blankStep(1)], submit_settings: flow.submit_settings ?? { button_text: 'Submit', success_message: 'Thank you. Your response has been submitted.' },
    });

    const changeScreens = (next) => setData('screens', next.map((step, index) => ({ ...step, fields: step.fields.map((field, fieldIndex) => ({ ...field, step: index + 1, order: fieldIndex + 1 })) })));
    const changeStep = (index, patch) => changeScreens(data.screens.map((step, i) => i === index ? { ...step, ...patch } : step));
    const changeField = (stepIndex, fieldIndex, patch) => changeScreens(data.screens.map((step, i) => i === stepIndex ? { ...step, fields: step.fields.map((field, j) => j === fieldIndex ? { ...field, ...patch } : field) } : step));
    const moveField = (stepIndex, fieldIndex, direction) => {
        const target = fieldIndex + direction;
        if (target < 0 || target >= data.screens[stepIndex].fields.length) return;
        const next = data.screens.map((step) => ({ ...step, fields: [...step.fields] })); [next[stepIndex].fields[fieldIndex], next[stepIndex].fields[target]] = [next[stepIndex].fields[target], next[stepIndex].fields[fieldIndex]]; changeScreens(next);
    };
    const save = (event) => { event.preventDefault(); put(route('client.flows.update', flow.uuid)); };
    const loadPreview = async () => {
        setPreviewError(null);
        try { const response = await fetch(route('client.flows.preview', flow.uuid), { headers: { Accept: 'application/json' } }); const body = await response.json(); if (!response.ok) throw new Error(body.message ?? 'Preview could not be compiled. Save the flow first.'); setPreview(body); } catch (error) { setPreviewError(error.message); }
    };
    const [metaAction, setMetaAction] = useState(null);
    const runMetaAction = (action) => {
        setMetaAction(action);
        router.post(route(`client.flows.${action}`, flow.uuid), {}, { preserveScroll: true, onFinish: () => setMetaAction(null) });
    };
    const [webFormAction, setWebFormAction] = useState(null);
    const [linkCopied, setLinkCopied] = useState(false);
    const webFormUrl = flow.public_slug ? route('public.flows.form.show', flow.public_slug) : null;
    const runWebFormAction = (action) => {
        setWebFormAction(action);
        router.post(route(`client.flows.web-form.${action}`, flow.uuid), {}, { preserveScroll: true, onFinish: () => setWebFormAction(null) });
    };
    const toggleRecaptcha = (checked) => {
        router.put(route('client.flows.web-form.recaptcha', flow.uuid), { recaptcha_enabled: checked }, { preserveScroll: true });
    };
    const copyWebFormUrl = () => {
        if (!webFormUrl) return;
        navigator.clipboard.writeText(webFormUrl);
        setLinkCopied(true);
        setTimeout(() => setLinkCopied(false), 2000);
    };

    return <ClientLayout title={`Build ${flow.name}`}>
        <Head title={`Build ${flow.name}`} />
        <form onSubmit={save} className="space-y-6">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><Link href={route('client.flows.index')} className="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-brand-600"><ArrowLeft className="h-4 w-4" /> All flows</Link><h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">Flow builder</h2></div><div className="flex flex-wrap gap-2"><button type="button" onClick={loadPreview} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:border-brand-400 dark:border-neutral-600"><Eye className="h-4 w-4" /> Preview Flow JSON</button><button type="button" disabled={metaAction !== null || flow.meta_sync_status === 'published'} onClick={() => runMetaAction('sync')} className="rounded-lg border border-brand-300 px-3 py-2 text-sm font-medium text-brand-700 disabled:opacity-50">{metaAction === 'sync' ? 'Syncing…' : 'Sync to Meta'}</button><button type="button" disabled={metaAction !== null || !flow.meta_flow_id || flow.meta_sync_status === 'published'} onClick={() => runMetaAction('publish')} className="rounded-lg bg-neutral-800 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900">{metaAction === 'publish' ? 'Publishing…' : 'Publish'}</button><button disabled={processing} className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50">Save flow</button></div></div>
            {props.flash?.success && <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{props.flash.success}</div>}
            {props.flash?.error && <div className="rounded-soft border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{props.flash.error}</div>}
            {flow.meta_validation_errors?.length > 0 && <section className="rounded-soft border border-red-200 bg-red-50 p-4 text-sm text-red-800"><h3 className="font-semibold">Meta Flow JSON validation errors</h3><ul className="mt-2 list-disc space-y-1 pl-5">{flow.meta_validation_errors.map((error, index) => <li key={`${error.message ?? 'error'}-${index}`}>{error.message ?? error.error ?? 'Meta reported an invalid Flow JSON property.'}{error.line_start ? ` (line ${error.line_start})` : ''}</li>)}</ul></section>}
            <section className="space-y-3 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Web form</h3>
                        <p className="text-sm text-neutral-500">Share this Flow as a standalone public web page — no WhatsApp required.</p>
                    </div>
                    <button type="button" disabled={webFormAction !== null} onClick={() => runWebFormAction(flow.web_form_enabled ? 'disable' : 'enable')} className={`rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-50 ${flow.web_form_enabled ? 'border border-neutral-300 dark:border-neutral-600' : 'bg-brand-600 text-white'}`}>
                        {webFormAction ? 'Working…' : flow.web_form_enabled ? 'Disable' : 'Enable'}
                    </button>
                </div>
                {flow.web_form_enabled && webFormUrl && <div className="space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <input readOnly value={webFormUrl} className="min-w-0 flex-1 rounded-soft border border-neutral-300 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                        <button type="button" onClick={copyWebFormUrl} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-600">{linkCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} {linkCopied ? 'Copied' : 'Copy link'}</button>
                        <button type="button" disabled={webFormAction !== null} onClick={() => runWebFormAction('regenerate')} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium disabled:opacity-50 dark:border-neutral-600"><RefreshCw className="h-4 w-4" /> Regenerate link</button>
                    </div>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={flow.recaptcha_enabled} onChange={(e) => toggleRecaptcha(e.target.checked)} /> Require reCAPTCHA on this form</label>
                </div>}
            </section>
            <section className="grid gap-4 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900 md:grid-cols-2"><label className="text-sm font-medium">Name<input value={data.name} onChange={(e) => setData('name', e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800" /></label><label className="text-sm font-medium">Category<select value={data.category} onChange={(e) => setData('category', e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800">{categories.map((category) => <option key={category}>{category}</option>)}</select></label><label className="text-sm font-medium md:col-span-2">Description<textarea value={data.description} onChange={(e) => setData('description', e.target.value)} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800" /></label><label className="text-sm font-medium">Submit button<input value={data.submit_settings.button_text ?? ''} onChange={(e) => setData('submit_settings', { ...data.submit_settings, button_text: e.target.value })} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800" /></label><label className="text-sm font-medium">Success message<input value={data.submit_settings.success_message ?? ''} onChange={(e) => setData('submit_settings', { ...data.submit_settings, success_message: e.target.value })} className="mt-1 w-full rounded-soft border border-neutral-300 bg-white px-3 py-2 dark:border-neutral-600 dark:bg-neutral-800" /></label></section>
            <div className="space-y-4">{data.screens.map((step, stepIndex) => <section key={step.id} className="rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div className="mb-4 flex items-center justify-between gap-3"><div className="flex items-center gap-3"><span className="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">Step {stepIndex + 1}</span><input value={step.title} onChange={(e) => changeStep(stepIndex, { title: e.target.value })} className="rounded-soft border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium dark:border-neutral-600 dark:bg-neutral-800" /></div>{data.screens.length > 1 && <button type="button" onClick={() => changeScreens(data.screens.filter((_, i) => i !== stepIndex))} className="text-sm text-red-600">Remove step</button>}</div><div className="space-y-3">{step.fields.map((field, fieldIndex) => <div key={field.id} className="grid gap-2 rounded-soft border border-neutral-200 p-3 dark:border-neutral-700 md:grid-cols-12"><input value={field.label} onChange={(e) => changeField(stepIndex, fieldIndex, { label: e.target.value })} className="rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800 md:col-span-3" placeholder="Label" /><select value={field.type} onChange={(e) => changeField(stepIndex, fieldIndex, { type: e.target.value, name: e.target.value === 'heading' ? '' : field.name })} className="rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800 md:col-span-2">{fieldTypes.map((type) => <option key={type}>{type}</option>)}</select>{field.type !== 'heading' && <input value={field.name ?? ''} onChange={(e) => changeField(stepIndex, fieldIndex, { name: e.target.value })} className="rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800 md:col-span-2" placeholder="field_name" />}<label className="flex items-center gap-1 text-xs md:col-span-1"><input type="checkbox" checked={field.required} onChange={(e) => changeField(stepIndex, fieldIndex, { required: e.target.checked })} /> Required</label><input value={field.helper_text ?? ''} onChange={(e) => changeField(stepIndex, fieldIndex, { helper_text: e.target.value })} className="rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800 md:col-span-2" placeholder="Helper text" /><div className="flex items-center gap-1 md:col-span-2"><button type="button" onClick={() => moveField(stepIndex, fieldIndex, -1)} className="rounded p-1 hover:bg-neutral-100"><ChevronUp className="h-4 w-4" /></button><button type="button" onClick={() => moveField(stepIndex, fieldIndex, 1)} className="rounded p-1 hover:bg-neutral-100"><ChevronDown className="h-4 w-4" /></button><button type="button" onClick={() => changeStep(stepIndex, { fields: step.fields.filter((_, i) => i !== fieldIndex) })} className="rounded p-1 text-red-600 hover:bg-red-50"><Trash2 className="h-4 w-4" /></button></div>{['select','radio','checkbox'].includes(field.type) && <input value={(field.options ?? []).map((option) => typeof option === 'string' ? option : option.title).join(', ')} onChange={(e) => changeField(stepIndex, fieldIndex, { options: e.target.value.split(',').map((option) => option.trim()).filter(Boolean) })} className="rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800 md:col-span-12" placeholder="Options, comma separated" />}</div>)}</div><button type="button" onClick={() => changeStep(stepIndex, { fields: [...step.fields, blankField(stepIndex + 1, step.fields.length + 1)] })} className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand-600"><Plus className="h-4 w-4" /> Add field</button></section>)}</div>
            <button type="button" onClick={() => changeScreens([...data.screens, blankStep(data.screens.length + 1)])} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-400 px-3 py-2 text-sm font-medium text-brand-700"><Plus className="h-4 w-4" /> Add step</button>
            {Object.keys(errors).length > 0 && <div className="rounded-soft border border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors)[0]}</div>}
            {previewError && <div className="rounded-soft border border-red-200 bg-red-50 p-3 text-sm text-red-700">{previewError}</div>}
            {preview && <section className="rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h3 className="mb-3 font-semibold">Compiled Meta Flow JSON</h3><pre className="max-h-[36rem] overflow-auto rounded-soft bg-neutral-50 p-4 text-xs text-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">{JSON.stringify(preview, null, 2)}</pre></section>}
        </form>
    </ClientLayout>;
}
