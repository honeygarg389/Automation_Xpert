import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, Input, Select, Textarea } from '@/Components/ui';

const CONNECTION_STATE_LABELS = { pending: 'Pending', connected: 'Active', paused: 'Paused' };
const CONNECTION_STATE_VARIANTS = { pending: 'warning', connected: 'success', paused: 'danger' };

/**
 * Phase 1C — Create Petpooja Connection. Extended in Phase 2A Slice 1 with
 * an explicit environment choice and an optional default phone country.
 *
 * ⚠️ THE ROOT-CAUSE FIX: `mode` is an explicit, server-validated field, not
 * something inferred from which fields happen to be non-empty. Switching
 * radios CLEARS the other mode's fields (setData, not merely hiding the
 * inputs) so a stale value from an earlier interaction can never be
 * submitted alongside a freshly-typed one — this is exactly the shape of
 * the measured defect where a leftover `outlet_id` from an earlier "existing
 * outlet" selection silently won over a freshly-typed "Burger King" in
 * `new_outlet_name`, because the previous version passed a non-existent
 * `data:` override to `post()` (not a real Inertia option) and so submitted
 * the raw, stale form state regardless of the radio shown on screen.
 *
 * ⚠️ `environment` is now the SAME shape of explicit, server-validated
 * field as `mode` — never inferred, never defaulted. Provider is still
 * fixed to 'petpooja' and is not a form field at all.
 *
 * ⚠️ `default_phone_country` starts as `''` (unselected) and is submitted as
 * `null`, never a guessed value — see the transform() in submit(). Petpooja's
 * own sample payloads show local, non-E.164 phone numbers; this platform
 * must never silently assume India or any other country. See
 * PhoneNumber::options() (server) for the shared country data source — the
 * same one the CSV/XLSX contact import screens already use.
 *
 * `preselect` (Section H): arrives from the Outlets directory's "Connect
 * Petpooja now" CTA after "Add Outlet Only". It is trusted only as far as
 * `outlets` still confirms the outlet is eligible RIGHT NOW — re-checked
 * here rather than assumed, because eligibility can change between the
 * redirect and this page loading (someone else connects it first). If it no
 * longer qualifies, this silently falls back to the ordinary empty form
 * instead of preselecting a now-wrong outlet.
 */
export default function Create({ workspaces, outlets, preselect, phoneCountries }) {
    const preselectedOutlet = useMemo(() => {
        if (!preselect?.outlet_id) return null;
        return outlets.find((o) => (
            o.id === preselect.outlet_id
            && o.workspace_id === preselect.workspace_id
            && o.status === 'active'
            && o.connection === null
        )) ?? null;
    }, [outlets, preselect]);

    const { data, setData, post, transform, processing, errors } = useForm({
        mode: 'existing',
        workspace_id: preselectedOutlet ? String(preselectedOutlet.workspace_id) : '',
        outlet_id: preselectedOutlet ? String(preselectedOutlet.id) : '',
        new_outlet_name: '',
        new_outlet_address: '',
        new_outlet_timezone: '',
        external_ref: '',
        allowed_ips: '',
        // No default — the admin must actively choose. See the class docblock.
        environment: '',
        // '' means "unselected", not any particular country — including
        // India, which is only ever pinned FIRST in the options list, never
        // pre-chosen. Submitted as null, never as ''.
        default_phone_country: '',
    });

    const outletsForWorkspace = useMemo(
        () => outlets.filter((o) => String(o.workspace_id) === String(data.workspace_id)),
        [outlets, data.workspace_id],
    );

    // Eligible: active status AND no non-archived connection already — the
    // exact rule RestaurantOutlet::eligibleForNewConnection() enforces
    // server-side. A connected/pending/paused outlet must never be
    // selectable here; it is surfaced instead in the helper list below.
    const eligibleOutlets = useMemo(
        () => outletsForWorkspace.filter((o) => o.status === 'active' && o.connection === null),
        [outletsForWorkspace],
    );

    const alreadyConnectedOutlets = useMemo(
        () => outletsForWorkspace.filter((o) => o.connection !== null),
        [outletsForWorkspace],
    );

    const setMode = (mode) => {
        if (mode === data.mode) return;
        // Clearing the OTHER mode's fields on every switch — not just on
        // submit — is what makes "stale outlet_id survives into new-outlet
        // mode" structurally impossible rather than merely unlikely.
        if (mode === 'existing') {
            setData((prev) => ({ ...prev, mode, new_outlet_name: '', new_outlet_address: '', new_outlet_timezone: '' }));
        } else {
            setData((prev) => ({ ...prev, mode, outlet_id: '' }));
        }
    };

    const submit = (e) => {
        e.preventDefault();

        const allowedIps = data.allowed_ips
            .split(/[\n,]/)
            .map((ip) => ip.trim())
            .filter(Boolean);

        // `transform()` registers how the CURRENT data is reshaped at submit
        // time — this is the real Inertia API for a one-off payload override.
        // (A `data:` key inside post()'s options is not: passing one there is
        // silently ignored and the raw, possibly-stale form state is sent
        // instead — the exact defect this rewrite fixes.)
        transform((formData) => ({
            ...formData,
            allowed_ips: allowedIps.length ? allowedIps : null,
            // '' -> null: an unselected country must reach the server as
            // "not set", never as an empty-string value that could later be
            // mistaken for a real (if blank) choice.
            default_phone_country: formData.default_phone_country || null,
        }));

        post(route('admin.restaurant.connections.store'));
    };

    return (
        <AdminLayout>
            <Head title="New Petpooja Connection" />

            <h1 className="mb-6 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                New Petpooja Connection
            </h1>

            {preselectedOutlet && (
                <div className="mb-4 max-w-2xl rounded-soft border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                    Connecting Petpooja for the outlet you just added: <strong>{preselectedOutlet.name}</strong>.
                    Enter its Petpooja-provided restID below.
                </div>
            )}

            <Card className="max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <div>
                        <span className="mb-2 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            Connection environment
                        </span>
                        <div className="flex gap-4 text-sm">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    name="environment"
                                    checked={data.environment === 'production'}
                                    onChange={() => setData('environment', 'production')}
                                />
                                Live Petpooja
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    name="environment"
                                    checked={data.environment === 'sandbox'}
                                    onChange={() => setData('environment', 'sandbox')}
                                />
                                AutomationXpert test/sandbox
                            </label>
                        </div>
                        {errors.environment && <p className="mt-1 text-xs text-coral-600 dark:text-coral-400">{errors.environment}</p>}
                        <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                            {data.environment === 'production'
                                ? 'A real, live Petpooja restaurant. Petpooja provides no sandbox of its own — this is the one integration path Petpooja actually offers. Creating it does not connect it; activation is a separate, explicit step below and requires all six live-activation requirements to be satisfied first (see below).'
                                : data.environment === 'sandbox'
                                    ? 'An AutomationXpert-only test connection for internal testing (this admin panel, curl, Postman) — Petpooja does not provide a sandbox and never delivers anything to a connection in this mode. Useful for exercising this screen and the webhook ingress without a real outlet.'
                                    : 'Choose one — there is no default.'}
                        </p>
                    </div>

                    <Select
                        label="Workspace / restaurant"
                        value={data.workspace_id}
                        onChange={(e) => setData((prev) => ({ ...prev, workspace_id: e.target.value, outlet_id: '' }))}
                        options={workspaces.map((w) => ({
                            value: w.id,
                            label: w.client_name ? `${w.client_name} — ${w.name}` : w.name,
                        }))}
                        error={errors.workspace_id}
                        required
                    />

                    <div>
                        <div className="mb-2 flex gap-4 text-sm">
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={data.mode === 'existing'}
                                    onChange={() => setMode('existing')}
                                />
                                Use an existing outlet
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="radio"
                                    checked={data.mode === 'new'}
                                    onChange={() => setMode('new')}
                                />
                                Create a new outlet
                            </label>
                        </div>
                        <p className="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
                            {data.mode === 'existing'
                                ? 'Attaches a restID to a branch that already exists but has no Petpooja connection yet — e.g. one created via "Add Outlet Only".'
                                : 'Creates the branch and connects its restID in one step.'}
                        </p>

                        {data.mode === 'existing' ? (
                            <>
                                <Select
                                    label="Outlet"
                                    value={data.outlet_id}
                                    onChange={(e) => setData('outlet_id', e.target.value)}
                                    options={eligibleOutlets.map((o) => ({ value: o.id, label: o.name }))}
                                    placeholder={
                                        !data.workspace_id
                                            ? 'Select a workspace first'
                                            : eligibleOutlets.length === 0
                                                ? 'No eligible outlets in this workspace'
                                                : 'Select an outlet...'
                                    }
                                    error={errors.outlet_id}
                                    disabled={!data.workspace_id}
                                />

                                {data.workspace_id && alreadyConnectedOutlets.length > 0 && (
                                    <div className="mt-3 rounded-soft border border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900">
                                        <p className="mb-2 text-xs font-medium text-neutral-500 dark:text-neutral-400">
                                            Already connected outlets in this workspace
                                        </p>
                                        <ul className="space-y-1.5">
                                            {alreadyConnectedOutlets.map((o) => (
                                                <li key={o.id} className="flex items-center justify-between text-sm">
                                                    <span className="flex items-center gap-2">
                                                        {o.name}
                                                        <Badge variant={CONNECTION_STATE_VARIANTS[o.connection.status] ?? 'default'} size="sm">
                                                            {CONNECTION_STATE_LABELS[o.connection.status] ?? o.connection.status}
                                                        </Badge>
                                                    </span>
                                                    <Link
                                                        href={route('admin.restaurant.connections.show', o.connection.uuid)}
                                                        className="text-brand-600 hover:underline dark:text-brand-400"
                                                    >
                                                        Configure
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="space-y-3">
                                <Input
                                    label="Outlet name"
                                    value={data.new_outlet_name}
                                    onChange={(e) => setData('new_outlet_name', e.target.value)}
                                    error={errors.new_outlet_name}
                                    required
                                />
                                <Input
                                    label="Address (optional)"
                                    value={data.new_outlet_address}
                                    onChange={(e) => setData('new_outlet_address', e.target.value)}
                                    error={errors.new_outlet_address}
                                />
                                <Input
                                    label="Timezone (optional)"
                                    placeholder="e.g. Asia/Kolkata"
                                    value={data.new_outlet_timezone}
                                    onChange={(e) => setData('new_outlet_timezone', e.target.value)}
                                    error={errors.new_outlet_timezone}
                                />
                            </div>
                        )}
                    </div>

                    <Input
                        label="Petpooja restID"
                        hint="Must be unique across every workspace on the platform. There is no Petpooja outlet-lookup API — enter it exactly as Petpooja shows it."
                        value={data.external_ref}
                        onChange={(e) => setData('external_ref', e.target.value)}
                        error={errors.external_ref}
                        required
                    />

                    <Textarea
                        label="IP allowlist (optional)"
                        hint="One exact IP address per line. Leave empty to allow any source IP. CIDR ranges are not supported in this phase."
                        rows={3}
                        value={data.allowed_ips}
                        onChange={(e) => setData('allowed_ips', e.target.value)}
                        error={errors['allowed_ips.0'] || errors.allowed_ips}
                    />

                    <div>
                        <Select
                            name="default_phone_country"
                            label="Default phone country (optional)"
                            value={data.default_phone_country}
                            onChange={(e) => setData('default_phone_country', e.target.value)}
                            options={phoneCountries.map((c) => ({ value: c.code, label: c.label }))}
                            placeholder="No country selected"
                            error={errors.default_phone_country}
                        />
                        <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs text-neutral-500 dark:text-neutral-400">
                            <li>Petpooja sends customer phone numbers as local digits, with no country code.</li>
                            <li>Selecting a country here lets a future ingestion step normalize those local numbers correctly.</li>
                            <li>
                                Left empty, this phase will not guess or coerce a phone number to any country — including
                                India, even though it is listed first below.
                            </li>
                        </ul>
                    </div>

                    <div className="rounded-soft border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
                        Provider is fixed to <strong>Petpooja</strong>.
                        {data.environment === 'production' && (
                            <>
                                {' '}A live connection is created Pending — it does not become Connected until you
                                separately generate a token and activate it on the connection&apos;s detail page,
                                which requires ALL of the following:
                                <ul className="mt-2 list-disc space-y-0.5 pl-5 text-xs">
                                    <li>current Terms acceptance for this workspace</li>
                                    <li>current Data Processing Agreement (DPA) acceptance for this workspace</li>
                                    <li>current Petpooja restaurant declaration acceptance for this workspace</li>
                                    <li>an active WhatsApp Business Account connected to this workspace</li>
                                    <li>this outlet authorized for live Petpooja (Outlets directory)</li>
                                    <li>a webhook token configured on this connection</li>
                                </ul>
                            </>
                        )}
                    </div>

                    <div className="flex justify-end gap-3">
                        <Button type="submit" variant="primary" disabled={processing}>
                            {data.environment === 'production' ? 'Create live connection' : 'Create connection'}
                        </Button>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
