import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, Checkbox, ConfirmDestructiveModal, Input, Modal, Select, Textarea } from '@/Components/ui';
import { Archive, Check, Copy, KeyRound, PauseCircle, Radio, RotateCcw, ShieldAlert, Shuffle, Trash2 } from 'lucide-react';
import { formatInTz } from '@/Utils/datetime';

const STATUS_VARIANTS = { pending: 'warning', connected: 'success', paused: 'danger', archived: 'default', disconnected: 'danger' };
const EVENT_STATUS_VARIANTS = { pending: 'brand', processed: 'success', failed: 'danger', quarantined: 'warning' };

/**
 * ⚠️ THE ONE-TIME-REVEAL GUARANTEE LIVES ENTIRELY IN REACT STATE.
 *
 * `revealedToken` is set ONLY from the JSON body of the POST this page fires
 * itself (never an Inertia prop, never anything the server sends on a normal
 * GET render of this page) — so a refresh, a back/forward navigation, or
 * simply reopening this page later can never show it again: the state that
 * would hold it does not exist until the button is clicked, and this
 * component remounts (state cleared) on every navigation away and back.
 */
function TokenPanel({ connection, canRotate }) {
    const [revealedToken, setRevealedToken] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirmingRotate, setConfirmingRotate] = useState(false);
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState(null);

    const isRotation = connection.token_configured;
    const isLive = connection.environment === 'production';

    const requestToken = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await window.axios.post(route('admin.restaurant.connections.token', connection.uuid));
            setRevealedToken(response.data.token);
        } catch (e) {
            setError(e.response?.data?.message || 'Failed to generate the token. Please try again.');
        } finally {
            setBusy(false);
            setConfirmingRotate(false);
        }
    };

    const copy = () => {
        navigator.clipboard.writeText(revealedToken);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const closeReveal = () => {
        setRevealedToken(null);
        setCopied(false);
        // A fresh GET picks up the new webhook_secret_rotated_at / token-configured
        // state without ever re-requesting or re-displaying the secret itself.
        router.reload({ only: ['connection'] });
    };

    return (
        <Card>
            <Card.Header title="Webhook token" />
            <Card.Body className="space-y-3">
                <div className="flex items-center gap-2">
                    <Badge variant={connection.token_configured ? 'success' : 'warning'}>
                        {connection.token_configured ? 'Configured' : 'Not configured'}
                    </Badge>
                    {connection.webhook_secret_rotated_at && (
                        <span className="text-sm text-neutral-500 dark:text-neutral-400">
                            Last rotated {connection.webhook_secret_rotated_at}
                        </span>
                    )}
                </div>

                {error && <p className="text-sm text-coral-700 dark:text-coral-300">{error}</p>}

                {canRotate && (
                    <Button
                        variant={isRotation ? 'secondary' : 'primary'}
                        disabled={busy}
                        onClick={() => (isRotation ? setConfirmingRotate(true) : requestToken())}
                    >
                        <KeyRound className="mr-1.5 h-4 w-4" />
                        {isRotation ? 'Rotate Token' : 'Generate Webhook Token'}
                    </Button>
                )}
            </Card.Body>

            {/* Rotation requires explicit confirmation — generating the FIRST token does not. */}
            <Modal show={confirmingRotate} onClose={() => setConfirmingRotate(false)} maxWidth="sm">
                <Modal.Header title="Rotate webhook token?" onClose={() => setConfirmingRotate(false)} />
                <Modal.Body>
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        The current token will stop working immediately. Any {isLive ? 'live Petpooja' : 'AutomationXpert test/sandbox'}{' '}
                        configuration using the old token will need to be updated with the new one.
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="secondary" onClick={() => setConfirmingRotate(false)}>Cancel</Button>
                    <Button variant="danger" disabled={busy} onClick={requestToken}>Rotate now</Button>
                </Modal.Footer>
            </Modal>

            {/* The one-time reveal. */}
            <Modal show={!!revealedToken} onClose={closeReveal} maxWidth="lg">
                <Modal.Header title="Webhook token generated" onClose={closeReveal} />
                <Modal.Body className="space-y-3">
                    <div className="flex items-center gap-2 rounded-soft border border-neutral-200 bg-neutral-50 px-3 py-2 font-mono text-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="flex-1 break-all">{revealedToken}</span>
                        <button type="button" onClick={copy} className="shrink-0 rounded p-1 hover:bg-neutral-200 dark:hover:bg-neutral-800">
                            {copied ? <Check className="h-4 w-4 text-emerald-500" /> : <Copy className="h-4 w-4" />}
                        </button>
                    </div>
                    <p className="flex items-start gap-2 text-sm font-medium text-coral-700 dark:text-coral-300">
                        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                        Copy this token now. For security, AutomationXpert will not display it again.
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button variant="primary" onClick={closeReveal}>I&apos;ve copied it — done</Button>
                </Modal.Footer>
            </Modal>
        </Card>
    );
}

export default function Show({ connection, recentEvents, recentRejections, webhookUrl, workspaces, outlets, phoneCountries }) {
    const page = usePage();
    const permissions = page.props.auth?.permissions ?? [];
    const flash = page.props.flash || {};
    const adminTz = page.props.timezone || 'Asia/Dhaka';

    const canRotateToken = permissions.includes('rotate_pos_webhook_secret');
    const canManage = permissions.includes('manage_pos_connections');
    const canActivate = permissions.includes('activate_pos_connections');
    const canMove = permissions.includes('move_pos_connections');

    const [urlCopied, setUrlCopied] = useState(false);
    const copyUrl = () => {
        navigator.clipboard.writeText(webhookUrl);
        setUrlCopied(true);
        setTimeout(() => setUrlCopied(false), 2000);
    };

    const [ipsDraft, setIpsDraft] = useState((connection.allowed_ips ?? []).join('\n'));
    const saveIps = (e) => {
        e.preventDefault();
        const allowedIps = ipsDraft.split(/[\n,]/).map((ip) => ip.trim()).filter(Boolean);
        router.put(route('admin.restaurant.connections.allowed-ips', connection.uuid), {
            allowed_ips: allowedIps.length ? allowedIps : null,
        }, { preserveScroll: true });
    };

    // '' (unselected) is a real, distinct draft state from any country code —
    // never initialized to a guessed value, even when the connection already
    // has no country set (that case IS '', correctly).
    const [phoneCountryDraft, setPhoneCountryDraft] = useState(connection.default_phone_country ?? '');
    const savePhoneCountry = (e) => {
        e.preventDefault();
        router.put(route('admin.restaurant.connections.default-phone-country', connection.uuid), {
            default_phone_country: phoneCountryDraft || null,
        }, { preserveScroll: true });
    };

    // Eligible on the CLIENT for either environment — the compliance gate
    // (a live connection's workspace must have accepted the current
    // Petpooja restaurant declaration) is enforced server-side in
    // activateLive() and surfaces as a flash.error on failure, the same
    // pattern every other server-side guard in this file already uses.
    // This intentionally does NOT try to predict that gate's outcome here.
    const isLive = connection.environment === 'production';
    const activationEligible = connection.token_configured && connection.status !== 'connected';

    const activate = () => {
        router.post(route('admin.restaurant.connections.activate', connection.uuid), {}, { preserveScroll: true });
    };

    const [confirmingPause, setConfirmingPause] = useState(false);
    const pause = () => {
        router.post(route('admin.restaurant.connections.pause', connection.uuid), {}, {
            preserveScroll: true,
            onFinish: () => setConfirmingPause(false),
        });
    };

    const [confirmingArchive, setConfirmingArchive] = useState(false);
    const archiveConnection = () => {
        router.post(route('admin.restaurant.connections.archive', connection.uuid), {}, {
            preserveScroll: true,
            onFinish: () => setConfirmingArchive(false),
        });
    };

    const [deleting, setDeleting] = useState(false);
    const deleteTestConnection = () => {
        router.delete(route('admin.restaurant.connections.destroy', connection.uuid), {
            onFinish: () => setDeleting(false),
        });
    };

    const [confirmingRestore, setConfirmingRestore] = useState(false);
    const restoreConnection = () => {
        router.post(route('admin.restaurant.connections.restore', connection.uuid), {}, {
            preserveScroll: true,
            onFinish: () => setConfirmingRestore(false),
        });
    };

    const [movingOpen, setMovingOpen] = useState(false);

    return (
        <AdminLayout>
            <Head title={`Connection — ${connection.external_ref}`} />

            <div className="mb-6 flex items-center gap-3">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {connection.workspace_name} — {connection.outlet?.name ?? 'No outlet'}
                </h1>
                <Badge variant={STATUS_VARIANTS[connection.status] ?? 'default'}>{connection.status}</Badge>
                <Badge variant="brand">{connection.environment}</Badge>
            </div>

            {flash.success && (
                <div className="mb-4 rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    {flash.success}
                </div>
            )}
            {flash.error && (
                <div className="mb-4 rounded-soft border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-800 dark:bg-coral-950/40 dark:text-coral-300">
                    {flash.error}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card>
                    <Card.Header title="Connection details" />
                    <Card.Body className="space-y-2 text-sm">
                        <Row label="Workspace" value={connection.workspace_name} />
                        <Row label="Outlet" value={connection.outlet?.name} />
                        <Row label="Outlet address" value={connection.outlet?.address} />
                        <Row label="Outlet timezone" value={connection.outlet?.timezone} />
                        <Row label="Provider" value="Petpooja" />
                        <Row label="Environment" value={isLive ? 'Live Petpooja' : 'AutomationXpert test/sandbox'} />
                        <Row label="restID" value={connection.external_ref} mono />
                        <Row
                            label="Default phone country"
                            value={
                                connection.default_phone_country
                                    ? (phoneCountries.find((c) => c.code === connection.default_phone_country)?.name ?? connection.default_phone_country)
                                    : 'Not set — no country assumed'
                            }
                        />
                        <Row label="Last webhook received" value={connection.last_event_at ?? 'Never'} />
                        <Row label="Last test status" value={connection.last_test_status ?? 'untested'} />
                        {connection.last_test_message && <Row label="Last test message" value={connection.last_test_message} />}
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Header title="Webhook endpoint" />
                    <Card.Body className="space-y-3">
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            One central URL for every Petpooja outlet. The restID and token are sent in the JSON body,
                            never in this URL.
                        </p>
                        <div className="flex items-center gap-2 rounded-soft border border-neutral-200 bg-neutral-50 px-3 py-2 font-mono text-xs dark:border-neutral-800 dark:bg-neutral-900">
                            <span className="flex-1 break-all">{webhookUrl}</span>
                            <button type="button" onClick={copyUrl} className="shrink-0 rounded p-1 hover:bg-neutral-200 dark:hover:bg-neutral-800">
                                {urlCopied ? <Check className="h-4 w-4 text-emerald-500" /> : <Copy className="h-4 w-4" />}
                            </button>
                        </div>
                    </Card.Body>
                </Card>

                <TokenPanel connection={connection} canRotate={canRotateToken} />

                <Card>
                    <Card.Header title={isLive ? 'Live activation' : 'Test/sandbox activation'} />
                    <Card.Body className="space-y-3">
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {isLive
                                ? 'This phase accepts and durably records Petpooja webhook deliveries only — it does not yet process bills, resolve customers, or send WhatsApp messages.'
                                : 'AutomationXpert test/sandbox ingress only. This phase does not process bills or send WhatsApp messages.'}
                        </p>
                        {connection.status === 'archived' ? (
                            <div className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <Badge variant="default">Archived — ingress blocked until restored</Badge>
                                    {canManage && connection.is_restorable && (
                                        <Button variant="primary" onClick={() => setConfirmingRestore(true)}>
                                            <RotateCcw className="mr-1.5 h-4 w-4" /> Restore Connection
                                        </Button>
                                    )}
                                </div>
                                {/* Without this, a legitimately-blocked restore looks identical to a
                                    missing/broken button — the exact confusion this section exists to
                                    close. is_restorable is false for a real reason (its outlet already
                                    has another live connection, or the outlet itself is archived), and
                                    that reason must be visible, not silent. */}
                                {canManage && !connection.is_restorable && (
                                    <p className="text-xs text-amber-700 dark:text-amber-300">
                                        This connection cannot be restored right now — its outlet either is archived
                                        or already has a different active Petpooja connection. Resolve that first
                                        (archive/move the other connection, or restore the outlet), then come back
                                        here to restore this one.
                                    </p>
                                )}
                            </div>
                        ) : connection.status === 'connected' ? (
                            <div className="flex items-center gap-3">
                                <Badge variant="success">Active — accepting ingress</Badge>
                                {canActivate && (
                                    <Button variant="secondary" onClick={() => setConfirmingPause(true)}>
                                        <PauseCircle className="mr-1.5 h-4 w-4" /> Pause
                                    </Button>
                                )}
                            </div>
                        ) : connection.status === 'paused' ? (
                            <div className="flex items-center gap-3">
                                <Badge variant="danger">Paused — ingress blocked</Badge>
                                {canActivate && (
                                    <Button variant="primary" onClick={activate}>
                                        <Radio className="mr-1.5 h-4 w-4" /> Resume Ingress
                                    </Button>
                                )}
                            </div>
                        ) : (
                            canActivate && (
                                <Button variant="primary" disabled={!activationEligible} onClick={activate}>
                                    <Radio className="mr-1.5 h-4 w-4" /> {isLive ? 'Activate Live Ingress' : 'Activate Test Ingress'}
                                </Button>
                            )
                        )}
                        {!connection.token_configured && connection.status !== 'connected' && connection.status !== 'archived' && (
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Generate a webhook token before activating.
                            </p>
                        )}
                        {isLive && connection.status !== 'connected' && connection.status !== 'archived' && (
                            <div className="text-xs text-amber-700 dark:text-amber-300">
                                <p>Live activation requires ALL six of the following:</p>
                                <ul className="mt-1 list-disc space-y-0.5 pl-4">
                                    <li>current Terms acceptance for this workspace</li>
                                    <li>current Data Processing Agreement (DPA) acceptance for this workspace</li>
                                    <li>current Petpooja restaurant declaration acceptance for this workspace</li>
                                    <li>an active WhatsApp Business Account connected to this workspace</li>
                                    <li>this outlet authorized for live Petpooja (Outlets directory)</li>
                                    <li>a webhook token configured on this connection</li>
                                </ul>
                                <p className="mt-1">
                                    If any is missing, activation is refused and the specific reason appears at the
                                    top of this page.
                                </p>
                            </div>
                        )}
                    </Card.Body>

                    <Modal show={confirmingPause} onClose={() => setConfirmingPause(false)} maxWidth="sm">
                        <Modal.Header title="Pause this connection?" onClose={() => setConfirmingPause(false)} />
                        <Modal.Body>
                            <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                Ingress will start rejecting {isLive ? 'Petpooja' : 'AutomationXpert test'} deliveries
                                immediately. Records and webhook history are kept, and it can be resumed at any time.
                            </p>
                        </Modal.Body>
                        <Modal.Footer>
                            <Button variant="secondary" onClick={() => setConfirmingPause(false)}>Cancel</Button>
                            <Button variant="danger" onClick={pause}>Pause ingress</Button>
                        </Modal.Footer>
                    </Modal>

                    <Modal show={confirmingRestore} onClose={() => setConfirmingRestore(false)} maxWidth="sm">
                        <Modal.Header title="Restore this connection?" onClose={() => setConfirmingRestore(false)} />
                        <Modal.Body className="space-y-3">
                            <ul className="list-disc space-y-1.5 pl-5 text-sm text-neutral-600 dark:text-neutral-400">
                                <li>The connection becomes <strong>Paused</strong>, not Connected.</li>
                                <li>Webhook ingress remains blocked — {isLive ? 'Petpooja' : 'AutomationXpert test'} deliveries are still rejected.</li>
                                <li>Its original restID, token and webhook/audit history are all preserved unchanged.</li>
                                <li>You must separately click <strong>Resume Ingress</strong> afterward to start accepting deliveries again.</li>
                            </ul>
                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                If this connection was archived over a security concern, rotate its token before
                                resuming ingress.
                            </p>
                        </Modal.Body>
                        <Modal.Footer>
                            <Button variant="secondary" onClick={() => setConfirmingRestore(false)}>Cancel</Button>
                            <Button variant="primary" onClick={restoreConnection}>Restore as Paused</Button>
                        </Modal.Footer>
                    </Modal>
                </Card>

                {connection.status !== 'archived' && (
                    <Card>
                        <Card.Header title="Archive connection" />
                        <Card.Body className="space-y-3">
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                Blocks ingress on this connection until it is explicitly restored. Unlike Pause, it
                                does not resume on its own — but it is reversible via Restore Connection, and
                                records, webhook events and audit history are always retained.
                            </p>
                            {canManage && (
                                <Button variant="secondary" onClick={() => setConfirmingArchive(true)}>
                                    <Archive className="mr-1.5 h-4 w-4" /> Archive connection
                                </Button>
                            )}
                        </Card.Body>

                        <Modal show={confirmingArchive} onClose={() => setConfirmingArchive(false)} maxWidth="sm">
                            <Modal.Header title="Archive this connection?" onClose={() => setConfirmingArchive(false)} />
                            <Modal.Body>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                                    This blocks ingress immediately. The connection, its restID and its webhook
                                    history are retained, and it can be reversed later via Restore Connection —
                                    resuming ingress after that still requires an explicit Resume.
                                </p>
                            </Modal.Body>
                            <Modal.Footer>
                                <Button variant="secondary" onClick={() => setConfirmingArchive(false)}>Cancel</Button>
                                <Button variant="danger" onClick={archiveConnection}>Archive connection</Button>
                            </Modal.Footer>
                        </Modal>
                    </Card>
                )}

                {canManage && connection.environment === 'sandbox' && (
                    <Card>
                        <Card.Header title="Delete test connection" />
                        <Card.Body className="space-y-3">
                            {connection.is_deletable ? (
                                <>
                                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                        Only available for a sandbox connection that has never accepted or rejected a
                                        single webhook event. This permanently removes the connection row itself —
                                        unlike Archive, there is no record left afterward.
                                    </p>
                                    <Button variant="danger" onClick={() => setDeleting(true)}>
                                        <Trash2 className="mr-1.5 h-4 w-4" /> Delete test connection
                                    </Button>
                                </>
                            ) : (
                                <p className="text-sm text-amber-700 dark:text-amber-300">
                                    This connection has webhook history, so it can no longer be deleted — use Archive
                                    instead to permanently block ingress while keeping the record.
                                </p>
                            )}
                        </Card.Body>
                    </Card>
                )}

                {canMove && (
                    <Card>
                        <Card.Header title="Move to a different workspace / outlet" />
                        <Card.Body className="space-y-3">
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                For an accidental workspace mapping. Guarded: only a sandbox connection with zero
                                accepted or rejected webhook events can be moved, it is paused first, its token is
                                rotated immediately, and both the source and destination are audit-logged.
                            </p>
                            {connection.is_movable ? (
                                <Button variant="secondary" onClick={() => setMovingOpen(true)}>
                                    <Shuffle className="mr-1.5 h-4 w-4" /> Move connection
                                </Button>
                            ) : (
                                <p className="text-sm text-amber-700 dark:text-amber-300">
                                    {connection.has_webhook_history
                                        ? 'This connection has webhook history, so it cannot be moved by this self-service flow — it requires Super Admin review.'
                                        : 'This connection is not eligible to be moved (production connections are never movable by this flow).'}
                                </p>
                            )}
                        </Card.Body>
                    </Card>
                )}

                <Card>
                    <Card.Header title="IP allowlist" />
                    <Card.Body>
                        <form onSubmit={saveIps} className="space-y-3">
                            <Textarea
                                label="Exact IP addresses (optional)"
                                hint="One exact IP per line. Leave empty to allow any source IP. CIDR ranges are not supported in this phase."
                                rows={3}
                                value={ipsDraft}
                                onChange={(e) => setIpsDraft(e.target.value)}
                                disabled={!canManage}
                            />
                            {canManage && <Button type="submit" variant="secondary">Save allowlist</Button>}
                        </form>
                    </Card.Body>
                </Card>

                <Card>
                    <Card.Header title="Default phone country" />
                    <Card.Body>
                        <form onSubmit={savePhoneCountry} className="space-y-3">
                            <Select
                                label="Default phone country (optional)"
                                value={phoneCountryDraft}
                                onChange={(e) => setPhoneCountryDraft(e.target.value)}
                                options={phoneCountries.map((c) => ({ value: c.code, label: c.label }))}
                                placeholder="No country selected"
                                disabled={!canManage}
                            />
                            <ul className="list-disc space-y-0.5 pl-5 text-xs text-neutral-500 dark:text-neutral-400">
                                <li>Petpooja sends customer phone numbers as local digits, with no country code.</li>
                                <li>Selecting a country here lets a future ingestion step normalize those local numbers correctly.</li>
                                <li>Left empty, this phase will not guess or coerce a phone number to any country — including India.</li>
                            </ul>
                            {canManage && <Button type="submit" variant="secondary">Save default phone country</Button>}
                        </form>
                    </Card.Body>
                </Card>

                <Card className="lg:col-span-2">
                    <Card.Header title="Recent accepted events" />
                    <Card.Body>
                        <EventsTable events={recentEvents} timezone={adminTz} />
                    </Card.Body>
                </Card>

                <Card className="lg:col-span-2">
                    <Card.Header title="Recent rejections" />
                    <Card.Body>
                        <RejectionsTable rejections={recentRejections} timezone={adminTz} />
                    </Card.Body>
                </Card>
            </div>

            <ConfirmDestructiveModal
                show={deleting}
                onClose={() => setDeleting(false)}
                onConfirm={deleteTestConnection}
                title="Delete this test connection?"
                body="This permanently removes the connection row. It only works because it has zero accepted or rejected webhook events — once any exist, this action is unavailable and Archive is the only option."
                confirmLabel="Delete permanently"
            />

            {canMove && (
                <MoveConnectionModal
                    show={movingOpen}
                    onClose={() => setMovingOpen(false)}
                    connection={connection}
                    workspaces={workspaces}
                    outlets={outlets}
                />
            )}
        </AdminLayout>
    );
}

/**
 * The guarded Super Admin correction flow (Task E). Deliberately mirrors
 * Create.jsx's explicit-`mode` shape rather than inferring existing/new from
 * which fields are filled — same root-cause fix, same reason.
 */
function MoveConnectionModal({ show, onClose, connection, workspaces, outlets }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        mode: 'existing',
        target_workspace_id: '',
        target_outlet_id: '',
        new_outlet_name: '',
        new_outlet_address: '',
        new_outlet_timezone: '',
        confirmed: false,
    });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const eligibleTargetOutlets = useMemo(
        () => outlets.filter((o) => (
            String(o.workspace_id) === String(data.target_workspace_id)
            && o.status === 'active'
            && o.connection === null
            && o.id !== connection.outlet_id
        )),
        [outlets, data.target_workspace_id, connection.outlet_id],
    );

    const setMode = (mode) => {
        if (mode === data.mode) return;
        if (mode === 'existing') {
            setData((prev) => ({ ...prev, mode, new_outlet_name: '', new_outlet_address: '', new_outlet_timezone: '' }));
        } else {
            setData((prev) => ({ ...prev, mode, target_outlet_id: '' }));
        }
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.restaurant.connections.move', connection.uuid), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Modal show={show} onClose={close} maxWidth="lg">
            <form onSubmit={submit}>
                <Modal.Header
                    title="Move this connection"
                    subtitle={`Currently: ${connection.workspace_name} — ${connection.outlet?.name ?? 'no outlet'}`}
                    onClose={close}
                />
                <Modal.Body className="space-y-4">
                    <Select
                        label="Target workspace"
                        value={data.target_workspace_id}
                        onChange={(e) => setData((prev) => ({ ...prev, target_workspace_id: e.target.value, target_outlet_id: '' }))}
                        options={workspaces.map((w) => ({
                            value: w.id,
                            label: w.client_name ? `${w.client_name} — ${w.name}` : w.name,
                        }))}
                        error={errors.target_workspace_id}
                        required
                    />

                    <div>
                        <div className="mb-2 flex gap-4 text-sm">
                            <label className="flex items-center gap-1.5">
                                <input type="radio" checked={data.mode === 'existing'} onChange={() => setMode('existing')} />
                                Use an existing outlet
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input type="radio" checked={data.mode === 'new'} onChange={() => setMode('new')} />
                                Create a new outlet
                            </label>
                        </div>

                        {data.mode === 'existing' ? (
                            <Select
                                label="Target outlet"
                                value={data.target_outlet_id}
                                onChange={(e) => setData('target_outlet_id', e.target.value)}
                                options={eligibleTargetOutlets.map((o) => ({ value: o.id, label: o.name }))}
                                placeholder={!data.target_workspace_id ? 'Select a workspace first' : 'Select an outlet...'}
                                error={errors.target_outlet_id}
                                disabled={!data.target_workspace_id}
                            />
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
                                    value={data.new_outlet_timezone}
                                    onChange={(e) => setData('new_outlet_timezone', e.target.value)}
                                    error={errors.new_outlet_timezone}
                                />
                            </div>
                        )}
                    </div>

                    <div className="rounded-soft border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                        This pauses the connection, reassigns it, and rotates its webhook token immediately. The old
                        token stops working — generate and share the new one before resuming ingress.
                    </div>

                    <Checkbox
                        label="I understand this moves the connection and immediately invalidates its current token."
                        checked={data.confirmed}
                        onChange={(e) => setData('confirmed', e.target.checked)}
                        error={errors.confirmed}
                    />
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="secondary" onClick={close}>Cancel</Button>
                    <Button type="submit" variant="danger" disabled={processing || !data.confirmed}>Move connection</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

function Row({ label, value, mono = false }) {
    return (
        <div className="flex justify-between gap-4 border-b border-neutral-100 py-1.5 dark:border-neutral-800/60">
            <span className="text-neutral-500 dark:text-neutral-400">{label}</span>
            <span className={mono ? 'font-mono text-xs' : ''}>{value ?? '—'}</span>
        </div>
    );
}

export function EventsTable({ events, timezone }) {
    if (!events.length) {
        return <div className="py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">No events received yet.</div>;
    }
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                    <th className="py-2 pr-4 font-medium">Received</th>
                    <th className="py-2 pr-4 font-medium">Event type</th>
                    <th className="py-2 pr-4 font-medium">Status</th>
                    <th className="py-2 pr-4 font-medium">Note</th>
                </tr>
            </thead>
            <tbody>
                {events.map((e) => (
                    <tr key={e.id} className="border-b border-neutral-100 dark:border-neutral-800/60">
                        <td className="py-2 pr-4">{formatInTz(e.received_at, timezone)}</td>
                        <td className="py-2 pr-4">{e.event_type ?? '—'}</td>
                        <td className="py-2 pr-4">
                            <Badge variant={EVENT_STATUS_VARIANTS[e.processing_status] ?? 'default'} size="sm">
                                {e.processing_status}
                            </Badge>
                        </td>
                        <td className="py-2 pr-4 text-neutral-500 dark:text-neutral-400">{e.failure_reason ?? '—'}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function RejectionsTable({ rejections, timezone }) {
    if (!rejections.length) {
        return <div className="py-6 text-center text-sm text-neutral-500 dark:text-neutral-400">No rejected requests.</div>;
    }
    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                    <th className="py-2 pr-4 font-medium">Received</th>
                    <th className="py-2 pr-4 font-medium">Reason</th>
                    <th className="py-2 pr-4 font-medium">Source IP</th>
                </tr>
            </thead>
            <tbody>
                {rejections.map((r) => (
                    <tr key={r.id} className="border-b border-neutral-100 dark:border-neutral-800/60">
                        <td className="py-2 pr-4">{formatInTz(r.received_at, timezone)}</td>
                        <td className="py-2 pr-4">
                            <Badge variant="warning" size="sm">{r.failure_reason}</Badge>
                        </td>
                        <td className="py-2 pr-4 font-mono text-xs">{r.source_ip ?? '—'}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
