import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle, AlignLeft, ArrowLeft, BadgeCheck, Camera, CalendarDays, Check, CheckSquare,
    ChevronDown, ChevronUp, CircleDot, Copy, Eye, GripVertical, Hash, Heading1, ListFilter, Mail,
    Mic, MoreVertical, Paperclip, Phone, Plus, RefreshCw, Save, Settings, Smile, Trash2, Type, X,
} from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Input, Select, Textarea, Toggle } from '@/Components/ui';

// Task 1 — choice-type fields start with ZERO options (empty state), not a
// pre-filled "Option 1". The Field Settings panel's "No options defined"
// empty state is what prompts the author to add their own.
const blankField = (step, order, type = 'text') => ({
    id: `field_${Date.now()}_${order}`, type,
    label: type === 'heading' ? 'New heading' : 'New field',
    name: type === 'heading' ? '' : `field_${step}_${order}`,
    required: false, helper_text: '', options: [], step, order,
});
const blankStep = (number) => ({ id: `step_${number}`, title: `Step ${number}`, fields: [blankField(number, 1)] });

const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

/** Task 4.12 — the field-type palette. No new types: this is the exact Slice 1 field-type list, just a clearer "click to add" affordance than a single generic button + dropdown. */
const FIELD_TYPE_META = {
    heading: { label: 'Heading', icon: Heading1 },
    text: { label: 'Text Input', icon: Type },
    number: { label: 'Number', icon: Hash },
    email: { label: 'Email', icon: Mail },
    phone: { label: 'Phone', icon: Phone },
    textarea: { label: 'Text Area', icon: AlignLeft },
    select: { label: 'Dropdown', icon: ListFilter },
    radio: { label: 'Single Choice', icon: CircleDot },
    checkbox: { label: 'Checkbox', icon: CheckSquare },
    date: { label: 'Date', icon: CalendarDays },
};

/** A small "● label" pill, reused for the Form Designer / Live Preview live-status badges. */
function LiveDot({ children, tone = 'emerald' }) {
    const toneClasses = tone === 'emerald'
        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
        : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400';
    const dotClasses = tone === 'emerald' ? 'bg-emerald-500' : 'bg-neutral-400';

    return (
        <span className={'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ' + toneClasses}>
            <span className={'h-1.5 w-1.5 rounded-full ' + dotClasses} /> {children}
        </span>
    );
}

export default function FlowsBuilder({ flow, categories, fieldTypes }) {
    const { props } = usePage();
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState(null);
    // Doubles as the Form Designer's "add to this step" target: whichever
    // step's badge is selected is both what Live Preview renders AND where
    // the palette below adds new fields. One selection, two consumers.
    const [activeStepIndex, setActiveStepIndex] = useState(0);
    const [expandedFieldId, setExpandedFieldId] = useState(null);
    const { data, setData, put, processing, errors } = useForm({
        name: flow.name, description: flow.description ?? '', category: flow.category ?? 'OTHER', status: flow.status,
        screens: flow.screens ?? [blankStep(1)], submit_settings: flow.submit_settings ?? { button_text: 'Submit', success_message: 'Thank you. Your response has been submitted.' },
        max_submissions: flow.max_submissions ?? null,
        limit_error_message: flow.limit_error_message ?? 'You have already reached the maximum number of submissions for this form.',
    });

    const changeScreens = (next) => setData('screens', next.map((step, index) => ({ ...step, fields: step.fields.map((field, fieldIndex) => ({ ...field, step: index + 1, order: fieldIndex + 1 })) })));
    const changeStep = (index, patch) => changeScreens(data.screens.map((step, i) => i === index ? { ...step, ...patch } : step));
    const changeField = (stepIndex, fieldIndex, patch) => changeScreens(data.screens.map((step, i) => i === stepIndex ? { ...step, fields: step.fields.map((field, j) => j === fieldIndex ? { ...field, ...patch } : field) } : step));
    const changeFieldType = (stepIndex, fieldIndex, type) => {
        const field = data.screens[stepIndex].fields[fieldIndex];
        const wasChoice = CHOICE_TYPES.includes(field.type);
        const isChoice = CHOICE_TYPES.includes(type);
        changeField(stepIndex, fieldIndex, { type, name: type === 'heading' ? '' : field.name, options: isChoice && wasChoice ? field.options : [] });
    };
    const addField = (stepIndex, type) => {
        const field = blankField(stepIndex + 1, data.screens[stepIndex].fields.length + 1, type);
        changeStep(stepIndex, { fields: [...data.screens[stepIndex].fields, field] });
        setExpandedFieldId(field.id);
    };
    const moveField = (stepIndex, fieldIndex, direction) => {
        const target = fieldIndex + direction;
        if (target < 0 || target >= data.screens[stepIndex].fields.length) return;
        const next = data.screens.map((step) => ({ ...step, fields: [...step.fields] })); [next[stepIndex].fields[fieldIndex], next[stepIndex].fields[target]] = [next[stepIndex].fields[target], next[stepIndex].fields[fieldIndex]]; changeScreens(next);
    };
    const addOption = (stepIndex, fieldIndex) => {
        const field = data.screens[stepIndex].fields[fieldIndex];
        changeField(stepIndex, fieldIndex, { options: [...(field.options ?? []), ''] });
    };
    const updateOption = (stepIndex, fieldIndex, optionIndex, value) => {
        const field = data.screens[stepIndex].fields[fieldIndex];
        const next = [...(field.options ?? [])];
        next[optionIndex] = value;
        changeField(stepIndex, fieldIndex, { options: next });
    };
    const removeOption = (stepIndex, fieldIndex, optionIndex) => {
        const field = data.screens[stepIndex].fields[fieldIndex];
        changeField(stepIndex, fieldIndex, { options: (field.options ?? []).filter((_, i) => i !== optionIndex) });
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
    const clampedActiveStep = Math.min(activeStepIndex, data.screens.length - 1);
    const totalFields = data.screens.reduce((sum, step) => sum + step.fields.length, 0);

    return <ClientLayout title={`Build ${flow.name}`}>
        <Head title={`Build ${flow.name}`} />
        <form onSubmit={save} className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="flex items-center gap-3">
                    <Link href={route('client.flows.index')} className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-neutral-200 text-neutral-500 hover:border-brand-300 hover:text-brand-600 dark:border-neutral-700"><ArrowLeft className="h-4 w-4" /></Link>
                    <div>
                        <h2 className="text-xl font-bold text-neutral-900 dark:text-neutral-100">Build {flow.name}</h2>
                        <p className="text-sm text-neutral-500">Build and customize your form with a guided interaction flow</p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={loadPreview} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium hover:border-brand-400 dark:border-neutral-600"><Eye className="h-4 w-4" /> Preview Flow JSON</button>
                    <button type="button" disabled={metaAction !== null || flow.meta_sync_status === 'published'} onClick={() => runMetaAction('sync')} className="rounded-lg border border-brand-300 px-3 py-2 text-sm font-medium text-brand-700 disabled:opacity-50">{metaAction === 'sync' ? 'Syncing…' : 'Sync to Meta'}</button>
                    <button type="button" disabled={metaAction !== null || !flow.meta_flow_id || flow.meta_sync_status === 'published'} onClick={() => runMetaAction('publish')} className="rounded-lg bg-neutral-800 px-3 py-2 text-sm font-medium text-white disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900">{metaAction === 'publish' ? 'Publishing…' : 'Publish'}</button>
                    <Link href={route('client.flows.index')} className="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 dark:border-neutral-600 dark:text-neutral-300">Cancel</Link>
                    <button disabled={processing} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"><Save className="h-4 w-4" /> {processing ? 'Saving…' : 'Save Changes'}</button>
                </div>
            </div>
            {props.flash?.success && <div className="rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{props.flash.success}</div>}
            {props.flash?.error && <div className="rounded-soft border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{props.flash.error}</div>}
            {flow.meta_validation_errors?.length > 0 && <section className="rounded-soft border border-red-200 bg-red-50 p-4 text-sm text-red-800"><h3 className="font-semibold">Meta Flow JSON validation errors</h3><ul className="mt-2 list-disc space-y-1 pl-5">{flow.meta_validation_errors.map((error, index) => <li key={`${error.message ?? 'error'}-${index}`}>{error.message ?? error.error ?? 'Meta reported an invalid Flow JSON property.'}{error.line_start ? ` (line ${error.line_start})` : ''}</li>)}</ul></section>}
            {/* Task 5 — non-blocking. Per Meta's Lifecycle documentation: editing and re-syncing a PUBLISHED flow reverts it to Draft on Meta's side until re-published; already-sent messages are unaffected. This informs, it never blocks — there are legitimate reasons to edit and republish. */}
            {flow.meta_sync_status === 'published' && (
                <div className="flex items-start gap-2 rounded-soft border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>This Flow is live on Meta. Saving and re-syncing will revert it to Draft on Meta&rsquo;s side until you publish again. Messages already sent using the current version are unaffected.</p>
                </div>
            )}
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
                    <div>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={flow.recaptcha_enabled} onChange={(e) => toggleRecaptcha(e.target.checked)} /> Require reCAPTCHA on this form</label>
                        {/* Task 3 — copy-only clarification. No logic change: recaptcha_enabled has only ever gated the public web-form route (Slice 7), never the WhatsApp Flow itself. This caption makes that existing scope legible instead of leaving it implicit. */}
                        <p className="ml-6 mt-1 text-xs text-neutral-500">Applies to the public web form link only — not shown inside WhatsApp.</p>
                    </div>
                </div>}
            </section>

            {/* General Information / Platform Integration — split into two cards
                (was one merged "Form Info" section) to match the reference layout.
                Nothing moved in or out of the group as a whole: Name/Description
                went left, Category/Max Submission Limit/Limit Error Message went
                right — the same fields Task 2/4 already introduced. */}
            <div className="grid gap-4 lg:grid-cols-2">
                <section className="space-y-4 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">General Information</h3>
                        <p className="text-sm text-neutral-500">Basic details about your form</p>
                    </div>
                    <Input name="flow_name" label="Name Of Form" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <Textarea name="flow_description" label="Description Of Form" placeholder="Briefly describe the purpose of this form…" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                </section>
                <section className="space-y-4 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Platform Integration</h3>
                        <p className="text-sm text-neutral-500">How the form interacts with our platform</p>
                    </div>
                    <Select name="flow_category" label="Select Category" value={data.category} onChange={(e) => setData('category', e.target.value)} options={categories} placeholder={null} />
                    <Input
                        name="max_submissions"
                        label="Max Submission Limit"
                        type="number"
                        min="1"
                        value={data.max_submissions ?? ''}
                        onChange={(e) => setData('max_submissions', e.target.value === '' ? null : Math.max(1, Number(e.target.value)))}
                        hint="Leave blank for unlimited submissions. Counts responses from WhatsApp and the web form together."
                    />
                    <Input
                        name="limit_error_message"
                        label="Limit Error Message"
                        value={data.limit_error_message ?? ''}
                        onChange={(e) => setData('limit_error_message', e.target.value)}
                        hint="Shown to web form visitors once the limit is reached."
                    />
                </section>
            </div>

            {/* Submission Settings — Submit button text + Success message, unchanged
                fields, now in their own titled card instead of sharing Form Info. */}
            <section className="space-y-4 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div>
                    <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Submission Settings</h3>
                    <p className="text-sm text-neutral-500">What happens after the form is submitted</p>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <Input name="submit_button_text" label="Text of Submit Button" value={data.submit_settings.button_text ?? ''} onChange={(e) => setData('submit_settings', { ...data.submit_settings, button_text: e.target.value })} />
                    <Input name="success_message" label="Success Message After Form Submitted" value={data.submit_settings.success_message ?? ''} onChange={(e) => setData('submit_settings', { ...data.submit_settings, success_message: e.target.value })} />
                </div>
            </section>

            {/* Form Designer — the field-type palette, pulled out of the per-step
                loop into its own section: it always adds to the step selected
                below in Form Canvas (the same selection Live Preview uses). */}
            <section className="space-y-3 rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Form Designer</h3>
                        <p className="text-sm text-neutral-500">Design your form fields and multi-step flow</p>
                    </div>
                    <LiveDot>Live Designer</LiveDot>
                </div>
                <div className="rounded-soft border border-neutral-100 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                    <p className="mb-3 text-sm font-semibold text-neutral-700 dark:text-neutral-300">Components <span className="font-normal text-neutral-400">— click to add fields to your form</span></p>
                    {/* Horizontal, non-wrapping row: on a screen too narrow to fit every
                        component, the row scrolls sideways instead of wrapping into a
                        second line with a big gap of unused space next to it. */}
                    <div className="flex gap-3 overflow-x-auto pb-4">
                        {fieldTypes.map((type) => {
                            const meta = FIELD_TYPE_META[type] ?? { label: type, icon: Plus };
                            const Icon = meta.icon;
                            return (
                                <button key={type} type="button" onClick={() => addField(clampedActiveStep, type)} className="flex h-[86px] w-24 shrink-0 flex-col items-center justify-center gap-1 rounded-lg border border-neutral-200 bg-white p-2 text-center hover:border-brand-400 hover:bg-brand-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-brand-600">
                                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"><Icon className="h-4 w-4" /></span>
                                    <span className="text-[11px] font-bold leading-tight text-neutral-700 dark:text-neutral-200">{meta.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* Form Canvas and Live Preview, side by side. */}
            <div className="lg:flex lg:items-start lg:gap-6">
                <div className="min-w-0 flex-1 space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Form Canvas</h3>
                            <p className="text-sm text-neutral-500">Your form&rsquo;s steps and fields. Click a field to configure it.</p>
                        </div>
                        <span className="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{totalFields} Field{totalFields === 1 ? '' : 's'}</span>
                    </div>
                    <div className="space-y-4">{data.screens.map((step, stepIndex) => <section key={step.id} className="rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div className="mb-4 flex items-center justify-between gap-3"><div className="flex items-center gap-3"><button type="button" onClick={() => setActiveStepIndex(stepIndex)} className={'rounded-full px-2.5 py-1 text-xs font-semibold ' + (clampedActiveStep === stepIndex ? 'bg-brand-600 text-white' : 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300')} title="Show this step in the preview and target it from Form Designer">Step {stepIndex + 1}/{data.screens.length}</button><input value={step.title} onChange={(e) => changeStep(stepIndex, { title: e.target.value })} className="rounded-soft border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium dark:border-neutral-600 dark:bg-neutral-800" /></div>{data.screens.length > 1 && <button type="button" onClick={() => changeScreens(data.screens.filter((_, i) => i !== stepIndex))} className="text-sm text-red-600">Remove step</button>}</div><div className="space-y-2">{step.fields.map((field, fieldIndex) => <FieldRow
                        key={field.id}
                        index={fieldIndex + 1}
                        field={field}
                        isFirst={fieldIndex === 0}
                        isLast={fieldIndex === step.fields.length - 1}
                        isExpanded={expandedFieldId === field.id}
                        onToggleExpand={() => setExpandedFieldId(expandedFieldId === field.id ? null : field.id)}
                        fieldTypes={fieldTypes}
                        onChangeType={(type) => changeFieldType(stepIndex, fieldIndex, type)}
                        onChange={(patch) => changeField(stepIndex, fieldIndex, patch)}
                        onMove={(direction) => moveField(stepIndex, fieldIndex, direction)}
                        onRemove={() => changeStep(stepIndex, { fields: step.fields.filter((_, i) => i !== fieldIndex) })}
                        onAddOption={() => addOption(stepIndex, fieldIndex)}
                        onUpdateOption={(optionIndex, value) => updateOption(stepIndex, fieldIndex, optionIndex, value)}
                        onRemoveOption={(optionIndex) => removeOption(stepIndex, fieldIndex, optionIndex)}
                    />)}</div>
                    </section>)}</div>
                    <button type="button" onClick={() => changeScreens([...data.screens, blankStep(data.screens.length + 1)])} className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-brand-400 px-3 py-2 text-sm font-medium text-brand-700"><Plus className="h-4 w-4" /> Add step</button>
                    {/* GripVertical handles on each row are real move controls (the ↑/↓ buttons), not free-drag —
                        this footnote says so plainly rather than promising drag-and-drop reordering that isn't built. */}
                    <p className="text-center text-xs text-neutral-400">Use the ↑ / ↓ controls on a field to reorder it. Click a field to configure it. Changes save when you click Save Changes.</p>
                </div>
                {/* Task 4.11 — live preview. Baseline scope, deliberately: a static, reactively-updating rendering of ONE selected step's fields inside a phone-frame mockup. NOT a fully-interactive step-by-step simulated chat (that is explicitly a stretch goal) — the step badges above select which step this panel shows, and every edit re-renders it instantly from `data`, with no save/reload.
                    lg:top-20, not lg:top-6: ClientLayout's own Topbar (Components/Topbar.jsx) is
                    itself `sticky top-0 h-14 z-30` — a smaller sticky offset here let this panel's
                    sticky top edge scroll UNDER that 56px topbar, cutting off the "Live Preview"
                    label and step badges. 80px clears the topbar with room to spare. */}
                <aside className="mt-6 shrink-0 lg:sticky lg:top-20 lg:mt-0 lg:w-80">
                    <LivePreviewPanel screens={data.screens} submitSettings={data.submit_settings} stepIndex={clampedActiveStep} onStepChange={setActiveStepIndex} businessName={flow.name} />
                </aside>
            </div>

            {Object.keys(errors).length > 0 && <div className="rounded-soft border border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors)[0]}</div>}
            {previewError && <div className="rounded-soft border border-red-200 bg-red-50 p-3 text-sm text-red-700">{previewError}</div>}
            {preview && <section className="rounded-soft-lg border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h3 className="mb-3 font-semibold">Compiled Meta Flow JSON</h3><pre className="max-h-[36rem] overflow-auto rounded-soft bg-neutral-50 p-4 text-xs text-neutral-700 dark:bg-neutral-950 dark:text-neutral-300">{JSON.stringify(preview, null, 2)}</pre></section>}
        </form>
    </ClientLayout>;
}

/**
 * Task 1 — the Field Settings panel. Replaces the old single-row editor
 * entirely (Label input, type select, name input, Required checkbox, helper
 * text input, and a comma-separated options input all crammed into one
 * `md:grid-cols-12` row) with a compact summary row that expands, on click,
 * into a full settings section below it. The summary row never carries an
 * editable control that could visually collide with another — it only shows
 * read-only label/type/required chips plus icon-button actions — so the
 * "Required checkbox overlapping Helper text" bug this replaces has nowhere
 * left to recur: the two no longer share a row at all.
 */
function FieldRow({ index, field, isFirst, isLast, isExpanded, onToggleExpand, fieldTypes, onChangeType, onChange, onMove, onRemove, onAddOption, onUpdateOption, onRemoveOption }) {
    const meta = FIELD_TYPE_META[field.type] ?? { label: field.type, icon: Type };
    const isHeading = field.type === 'heading';
    const isChoice = CHOICE_TYPES.includes(field.type);
    const options = field.options ?? [];

    return (
        <div className="rounded-soft border border-neutral-200 dark:border-neutral-700">
            <div className="flex items-start gap-2 p-3">
                <GripVertical className="mt-1 h-4 w-4 shrink-0 text-neutral-300" aria-hidden="true" />
                <button type="button" onClick={onToggleExpand} aria-expanded={isExpanded} className="min-w-0 flex-1 text-left">
                    <span className="flex flex-wrap items-baseline gap-1.5">
                        <span className="text-xs font-semibold italic text-neutral-400">#{index}</span>
                        <span className="truncate text-sm font-semibold text-neutral-800 dark:text-neutral-100">{field.label || 'Untitled field'}</span>
                        {field.required && <span className="text-red-500">*</span>}
                    </span>
                    <span className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">{meta.label}</span>
                        {!isHeading && field.name && <span className="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-[10px] text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{field.name}</span>}
                    </span>
                </button>
                <div className="flex shrink-0 items-center gap-1">
                    <button type="button" onClick={() => onMove(-1)} disabled={isFirst} className="rounded p-1 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800" aria-label="Move field up"><ChevronUp className="h-4 w-4" /></button>
                    <button type="button" onClick={() => onMove(1)} disabled={isLast} className="rounded p-1 hover:bg-neutral-100 disabled:opacity-30 dark:hover:bg-neutral-800" aria-label="Move field down"><ChevronDown className="h-4 w-4" /></button>
                    <button type="button" onClick={onRemove} className="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" aria-label="Delete field"><Trash2 className="h-4 w-4" /></button>
                    <button type="button" onClick={onToggleExpand} aria-expanded={isExpanded} aria-label={isExpanded ? 'Collapse field settings' : 'Configure field'} className="rounded p-1 hover:bg-neutral-100 dark:hover:bg-neutral-800">
                        {isExpanded ? <ChevronUp className="h-4 w-4" /> : <Settings className="h-4 w-4" />}
                    </button>
                </div>
            </div>
            {isExpanded && (
                <div className="space-y-4 border-t border-neutral-200 p-4 dark:border-neutral-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-neutral-400">Field settings</p>
                    <Select id={`${field.id}-type`} label="Type" value={field.type} onChange={(e) => onChangeType(e.target.value)} options={fieldTypes.map((type) => ({ value: type, label: FIELD_TYPE_META[type]?.label ?? type }))} placeholder={null} />
                    <Input id={`${field.id}-label`} label="Display label" value={field.label} onChange={(e) => onChange({ label: e.target.value })} />
                    {!isHeading && (
                        <>
                            <Input id={`${field.id}-name`} label="Name" value={field.name ?? ''} onChange={(e) => onChange({ name: e.target.value })} hint="Unique identifier for payload. Use snake_case." />
                            <Input id={`${field.id}-helper`} label="Helper / placeholder text" value={field.helper_text ?? ''} onChange={(e) => onChange({ helper_text: e.target.value })} />
                            <Toggle label="Required" checked={!!field.required} onChange={(checked) => onChange({ required: checked })} />
                        </>
                    )}
                    {isChoice && (
                        <div className="space-y-2 rounded-soft border border-neutral-200 p-3 dark:border-neutral-700">
                            <div className="flex items-center justify-between">
                                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">Selection options</p>
                                <button type="button" onClick={onAddOption} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-brand-400 px-2 py-1 text-xs font-medium text-brand-700"><Plus className="h-3.5 w-3.5" /> Add option</button>
                            </div>
                            {options.length === 0 ? (
                                <p className="text-sm text-neutral-400">No options defined</p>
                            ) : (
                                <div className="space-y-2">
                                    {options.map((option, optionIndex) => (
                                        <div key={optionIndex} className="flex items-center gap-2">
                                            <input
                                                value={typeof option === 'string' ? option : option.title}
                                                onChange={(e) => onUpdateOption(optionIndex, e.target.value)}
                                                placeholder={`Option ${optionIndex + 1}`}
                                                className="flex-1 rounded-soft border border-neutral-300 bg-white px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                                            />
                                            <button type="button" onClick={() => onRemoveOption(optionIndex)} aria-label="Remove option" className="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"><X className="h-4 w-4" /></button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function LivePreviewPanel({ screens, submitSettings, stepIndex, onStepChange, businessName }) {
    const step = screens[stepIndex];
    const isLastStep = stepIndex === screens.length - 1;
    const initial = (businessName || 'F').trim().charAt(0).toUpperCase() || 'F';

    return (
        <div className="rounded-soft-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-xs font-bold uppercase tracking-wide text-neutral-500">Live Preview</span>
                <LiveDot>Live</LiveDot>
            </div>
            {screens.length > 1 && (
                <div className="mb-3 flex flex-wrap gap-1">
                    {screens.map((s, index) => (
                        <button key={s.id} type="button" onClick={() => onStepChange(index)} className={'rounded-full px-2 py-0.5 text-xs font-medium ' + (index === stepIndex ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300')}>
                            {index + 1}
                        </button>
                    ))}
                </div>
            )}
            {/* WhatsApp-styled phone frame — chrome only, purely decorative
                (business name/online status/icons below are not wired to real
                data). What IS real and reactive is the message body: it renders
                the actual selected step's fields from `data`, not a static
                "tap to preview" placeholder, so an author sees their real form. */}
            <div className="mx-auto w-full max-w-[260px] overflow-hidden rounded-[2rem] border-[6px] border-neutral-900 bg-white shadow-soft-xl dark:border-neutral-700">
                <div className="flex items-center gap-2 bg-brand-600 px-3 py-2.5 text-white">
                    <span className="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/20 text-[11px] font-bold">
                        {initial}
                        <BadgeCheck className="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full bg-white text-emerald-500" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-semibold leading-none">WhatsApp Business</span>
                        <span className="block text-[10px] text-white/70">Online</span>
                    </span>
                    <Camera className="h-3.5 w-3.5 shrink-0 text-white/80" />
                    <MoreVertical className="h-3.5 w-3.5 shrink-0 text-white/80" />
                </div>
                <div className="max-h-[380px] min-h-[380px] overflow-y-auto bg-neutral-50 p-3 dark:bg-neutral-950">
                    <p className="mb-2 text-center text-[10px] font-semibold uppercase tracking-wide text-neutral-400">Today</p>
                    {!step ? (
                        <p className="pt-16 text-center text-xs text-neutral-400">Add a step to preview it here.</p>
                    ) : (
                        <div className="space-y-3 rounded-lg bg-white p-3 shadow-soft dark:bg-neutral-900">
                            <p className="text-xs font-semibold text-neutral-400">{step.title}</p>
                            {step.fields.map((field) => <PreviewField key={field.id} field={field} />)}
                            <button type="button" disabled className="mt-2 w-full rounded-soft bg-brand-600 py-2 text-xs font-semibold text-white opacity-90">
                                {isLastStep ? (submitSettings?.button_text || 'Submit') : 'Continue'}
                            </button>
                        </div>
                    )}
                </div>
                <div className="flex items-center gap-2 border-t border-neutral-200 bg-white px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-900">
                    <Smile className="h-4 w-4 shrink-0 text-neutral-400" />
                    <span className="flex-1 truncate rounded-full bg-neutral-100 px-2 py-1 text-[10px] text-neutral-400 dark:bg-neutral-800">Type a message</span>
                    <Paperclip className="h-3.5 w-3.5 shrink-0 text-neutral-400" />
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white"><Mic className="h-3 w-3" /></span>
                </div>
            </div>
        </div>
    );
}

function PreviewField({ field }) {
    if (field.type === 'heading') {
        return <p className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">{field.label || 'Heading'}</p>;
    }

    const label = <label className="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{field.label || 'Field'}{field.required && <span className="text-red-500"> *</span>}</label>;

    return (
        <div>
            {label}
            {field.type === 'textarea' && <div className="h-12 rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs text-neutral-300 dark:border-neutral-700 dark:bg-neutral-900" />}
            {field.type === 'select' && <div className="flex items-center justify-between rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900"><span>{(field.options ?? [])[0]?.title ?? (field.options ?? [])[0] ?? 'Select…'}</span><ChevronDown className="h-3 w-3" /></div>}
            {field.type === 'radio' && <div className="space-y-1">{(field.options ?? []).slice(0, 4).map((option, i) => <div key={i} className="flex items-center gap-1.5 text-xs text-neutral-500"><span className="h-3 w-3 rounded-full border border-neutral-400" /> {typeof option === 'string' ? option : option.title}</div>)}</div>}
            {field.type === 'checkbox' && <div className="space-y-1">{(field.options ?? []).slice(0, 4).map((option, i) => <div key={i} className="flex items-center gap-1.5 text-xs text-neutral-500"><span className="h-3 w-3 rounded-sm border border-neutral-400" /> {typeof option === 'string' ? option : option.title}</div>)}</div>}
            {field.type === 'date' && <div className="flex items-center gap-1.5 rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900"><CalendarDays className="h-3 w-3" /> dd/mm/yyyy</div>}
            {['text', 'number', 'email', 'phone'].includes(field.type) && <div className="rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs text-neutral-300 dark:border-neutral-700 dark:bg-neutral-900">{field.type === 'email' ? 'name@example.com' : field.type === 'phone' ? '+1 555 000 0000' : field.type === 'number' ? '0' : 'Your answer'}</div>}
            {field.helper_text && <p className="mt-0.5 text-[10px] text-neutral-400">{field.helper_text}</p>}
        </div>
    );
}
