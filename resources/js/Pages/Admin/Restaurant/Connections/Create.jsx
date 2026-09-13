import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, Input, Select, Textarea } from '@/Components/ui';

const CONNECTION_STATE_LABELS = { pending: 'Pending', connected: 'Active', paused: 'Paused' };
const CONNECTION_STATE_VARIANTS = { pending: 'warning', connected: 'success', paused: 'danger' };

/**
 * Phase 1C — Create Sandbox Petpooja Connection.
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
 * Provider is fixed to 'petpooja' and environment to 'sandbox' — neither is
 * a form field at all, so there is nothing here for a client to tamper with
 * to request anything else. The server enforces the same thing independently
 * (StorePosConnectionRequest never accepts either field; the controller
 * always calls createSandboxConnection()).
 *
 * `preselect` (Section H): arrives from the Outlets directory's "Connect
 * Petpooja now" CTA after "Add Outlet Only". It is trusted only as far as
 * `outlets` still confirms the outlet is eligible RIGHT NOW — re-checked
 * here rather than assumed, because eligibility can change between the
 * redirect and this page loading (someone else connects it first). If it no
 * longer qualifies, this silently falls back to the ordinary empty form
 * instead of preselecting a now-wrong outlet.
 */
export default function Create({ workspaces, outlets, preselect }) {
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
        }));

        post(route('admin.restaurant.connections.store'));
    };

    return (
        <AdminLayout>
            <Head title="New Sandbox Petpooja Connection" />

            <h1 className="mb-6 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                New Sandbox Petpooja Connection
            </h1>

            {preselectedOutlet && (
                <div className="mb-4 max-w-2xl rounded-soft border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                    Connecting Petpooja for the outlet you just added: <strong>{preselectedOutlet.name}</strong>.
                    Enter its Petpooja-provided restID below.
                </div>
            )}

            <Card className="max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
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

                    <div className="rounded-soft border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400">
                        Provider is fixed to <strong>Petpooja</strong>. Environment is fixed to <strong>Sandbox</strong>.
                        Production activation will be available after required compliance and sender-connection gates
                        are completed.
                    </div>

                    <div className="flex justify-end gap-3">
                        <Button type="submit" variant="primary" disabled={processing}>
                            Create sandbox connection
                        </Button>
                    </div>
                </form>
            </Card>
        </AdminLayout>
    );
}
