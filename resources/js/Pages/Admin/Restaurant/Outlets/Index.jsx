import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, Dropdown, Input, Modal, Pagination, Select } from '@/Components/ui';
import { Archive, BadgeCheck, MessageSquare, MoreVertical, Pencil, Plug, Plus, RotateCcw, Search, Settings, ShieldCheck } from 'lucide-react';

const OUTLET_STATUS_VARIANTS = { active: 'success', archived: 'default' };

/**
 * The outlet's physical status (Active/Archived) and its Petpooja
 * connection's status are two independent axes — an outlet can be Active or
 * Archived with a connection in ANY of these five states, `not_connected`
 * included. `archived` here is a real, distinct state (not the same thing
 * as "not connected") and must never collapse into it.
 */
const CONNECTION_STATE_LABELS = {
    not_connected: 'Not connected',
    pending: 'Pending',
    connected: 'Active',
    paused: 'Paused',
    archived: 'Archived',
};

const CONNECTION_STATE_VARIANTS = {
    not_connected: 'default',
    pending: 'warning',
    connected: 'success',
    paused: 'danger',
    archived: 'default',
};

/**
 * Phase 1C, Task B — the Outlets directory: outlets exist and can be managed
 * independently of whether any one of them ever gets a Petpooja connection.
 * "Add Outlet" here creates an ACTIVE, unconnected restaurant_outlets row
 * scoped to exactly one workspace — it becomes selectable in that same
 * workspace's "Use existing outlet" dropdown on the New Connection page
 * (RestaurantOutlet::eligibleForNewConnection()), and in no other workspace's.
 */
export default function Index({ outlets, workspaces, filters, digitalBillDeliveryOptions = {} }) {
    const page = usePage();
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('manage_pos_connections');
    const canAuthorizeForLivePos = permissions.includes('authorize_pos_outlets');
    const flash = page.props.flash || {};

    const [search, setSearch] = useState(filters?.search ?? '');
    const [workspaceFilter, setWorkspaceFilter] = useState(filters?.workspace_id ?? '');
    const statusTab = filters?.status === 'archived' ? 'archived' : 'active';

    const submitFilters = (e) => {
        e?.preventDefault();
        router.get(route('admin.restaurant.outlets.index'), {
            search,
            workspace_id: workspaceFilter || undefined,
            status: statusTab,
        }, { preserveState: true, replace: true });
    };

    const switchTab = (tab) => {
        router.get(route('admin.restaurant.outlets.index'), {
            search,
            workspace_id: workspaceFilter || undefined,
            status: tab,
        }, { preserveState: true, replace: true });
    };

    const [addOpen, setAddOpen] = useState(false);
    const [editingOutlet, setEditingOutlet] = useState(null);
    const [archivingOutlet, setArchivingOutlet] = useState(null);
    const [restoringOutlet, setRestoringOutlet] = useState(null);
    const [authorizingOutlet, setAuthorizingOutlet] = useState(null);
    const [messagingSettingsOutlet, setMessagingSettingsOutlet] = useState(null);
    const [deliveryConfigurationOutlet, setDeliveryConfigurationOutlet] = useState(null);

    const rows = outlets.data ?? [];

    const workspaceOptions = workspaces.map((w) => ({
        value: w.id,
        label: w.client_name ? `${w.client_name} — ${w.name}` : w.name,
    }));

    return (
        <AdminLayout>
            <Head title="Restaurant Outlets" />

            <div className="mb-2 flex items-center justify-between">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Outlets</h1>
                {canManage && (
                    <div className="flex gap-3">
                        <Link href={route('admin.restaurant.branding.index')}>
                            <Button variant="secondary">
                                <Settings className="mr-1.5 h-4 w-4" /> Restaurant Branding
                            </Button>
                        </Link>
                        <Link href={route('admin.restaurant.connections.create')}>
                            <Button variant="secondary">
                                <Plug className="mr-1.5 h-4 w-4" /> New Petpooja Connection
                            </Button>
                        </Link>
                        <Button variant="primary" onClick={() => setAddOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" /> Add Outlet Only
                        </Button>
                    </div>
                )}
            </div>

            {canManage && (
                <p className="mb-6 max-w-3xl text-xs text-neutral-500 dark:text-neutral-400">
                    <strong>Add Outlet Only</strong> creates a branch now and connects Petpooja later.{' '}
                    <strong>New Petpooja Connection → Use Existing Outlet</strong> attaches a restID to a branch
                    already created this way. <strong>New Petpooja Connection → Create New Outlet</strong> does both
                    at once.
                </p>
            )}

            {flash.success && (
                <div className="mb-4 rounded-soft border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    {flash.success}
                    {flash.connectCta && (
                        <div className="mt-2">
                            <Link
                                href={route('admin.restaurant.connections.create', {
                                    workspace_id: flash.connectCta.workspace_id,
                                    outlet_id: flash.connectCta.outlet_id,
                                })}
                                className="inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline dark:text-brand-400"
                            >
                                <Plug className="h-4 w-4" /> Connect Petpooja now
                            </Link>
                        </div>
                    )}
                </div>
            )}
            {flash.error && (
                <div className="mb-4 rounded-soft border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:border-coral-800 dark:bg-coral-950/40 dark:text-coral-300">
                    {flash.error}
                </div>
            )}

            <Card>
                <div className="mb-4 flex gap-1 border-b border-neutral-200 dark:border-neutral-800">
                    {[
                        { key: 'active', label: 'Active' },
                        { key: 'archived', label: 'Archived' },
                    ].map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => switchTab(tab.key)}
                            className={[
                                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition duration-150',
                                statusTab === tab.key
                                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                    : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700 dark:text-neutral-400 dark:hover:border-neutral-600 dark:hover:text-neutral-200',
                            ].join(' ')}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <form onSubmit={submitFilters} className="mb-4 flex flex-wrap gap-2">
                    <Input
                        name="search"
                        placeholder="Search outlet or workspace..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-xs"
                    />
                    <Select
                        className="max-w-xs"
                        value={workspaceFilter}
                        onChange={(e) => setWorkspaceFilter(e.target.value)}
                        options={workspaceOptions}
                        placeholder="All workspaces"
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="h-4 w-4" />
                    </Button>
                </form>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 text-left text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                                <th className="py-2 pr-4 font-medium">Workspace</th>
                                <th className="py-2 pr-4 font-medium">Outlet</th>
                                <th className="py-2 pr-4 font-medium">Address</th>
                                <th className="py-2 pr-4 font-medium">Timezone</th>
                                <th className="py-2 pr-4 font-medium">Status</th>
                                <th className="py-2 pr-4 font-medium">Petpooja connection</th>
                                <th className="py-2 pr-4 font-medium">Live POS authorization</th>
                                <th className="py-2 pr-4 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((o) => (
                                <tr key={o.uuid} className="border-b border-neutral-100 dark:border-neutral-800/60">
                                    <td className="py-2 pr-4">{o.workspace_name ?? '—'}</td>
                                    <td className="py-2 pr-4">{o.name}</td>
                                    <td className="py-2 pr-4 text-neutral-500 dark:text-neutral-400">{o.address ?? '—'}</td>
                                    <td className="py-2 pr-4 text-neutral-500 dark:text-neutral-400">{o.timezone ?? '—'}</td>
                                    <td className="py-2 pr-4">
                                        <Badge variant={OUTLET_STATUS_VARIANTS[o.status] ?? 'default'} size="sm">{o.status}</Badge>
                                    </td>
                                    <td className="py-2 pr-4">
                                        <Badge variant={CONNECTION_STATE_VARIANTS[o.connection_state] ?? 'default'} size="sm">
                                            {CONNECTION_STATE_LABELS[o.connection_state] ?? o.connection_state}
                                        </Badge>
                                    </td>
                                    <td className="py-2 pr-4">
                                        {o.authorized_for_live_pos ? (
                                            <Badge variant="success" size="sm">
                                                <BadgeCheck className="mr-1 inline h-3.5 w-3.5" /> Authorized
                                            </Badge>
                                        ) : (
                                            <Badge variant="default" size="sm">Not authorized</Badge>
                                        )}
                                    </td>
                                    <td className="py-2 pr-4 text-right">
                                        <OutletRowActions
                                            outlet={o}
                                            canManage={canManage}
                                            canAuthorizeForLivePos={canAuthorizeForLivePos}
                                            onEdit={() => setEditingOutlet(o)}
                                            onMessagingSettings={() => setMessagingSettingsOutlet(o)}
                                            onDeliveryConfiguration={() => setDeliveryConfigurationOutlet(o)}
                                            onAuthorize={() => setAuthorizingOutlet(o)}
                                            onArchive={() => setArchivingOutlet(o)}
                                            onRestore={() => setRestoringOutlet(o)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {rows.length === 0 && (
                        <div className="py-8 text-center text-neutral-500 dark:text-neutral-400">
                            {statusTab === 'archived' ? 'No archived outlets.' : 'No outlets yet.'}
                        </div>
                    )}
                </div>

                <Pagination data={outlets} className="mt-4" />
            </Card>

            <AddOutletModal show={addOpen} onClose={() => setAddOpen(false)} workspaceOptions={workspaceOptions} />
            <EditOutletModal outlet={editingOutlet} onClose={() => setEditingOutlet(null)} />
            <MessagingSettingsModal outlet={messagingSettingsOutlet} onClose={() => setMessagingSettingsOutlet(null)} />
            <DigitalBillDeliveryConfigModal outlet={deliveryConfigurationOutlet} options={digitalBillDeliveryOptions[String(deliveryConfigurationOutlet?.workspace_id)] ?? { senders: [] }} onClose={() => setDeliveryConfigurationOutlet(null)} />
            <ArchiveOutletModal outlet={archivingOutlet} onClose={() => setArchivingOutlet(null)} />
            <RestoreOutletModal outlet={restoringOutlet} onClose={() => setRestoringOutlet(null)} />
            <AuthorizeOutletModal outlet={authorizingOutlet} onClose={() => setAuthorizingOutlet(null)} />
        </AdminLayout>
    );
}

/**
 * The compact "Actions" menu a table-row refactor collapsed Configure, Edit
 * outlet, Authorize and Archive outlet into. Each item keeps EXACTLY the
 * visibility condition, click handler, confirmation modal and route it had
 * as a separate inline control — only the presentation changed.
 *
 * Restore is deliberately NOT part of this menu: it was out of this
 * refactor's scope (only Configure/Edit/Authorize/Archive were named), it
 * only ever appears for an archived outlet — exactly when Edit/Authorize/
 * Archive never do — and it reads more naturally as the one primary action
 * on an archived row than buried behind a three-dot menu.
 *
 * The trigger itself only renders when at least one menu item actually
 * would — an admin with no relevant permission, viewing an outlet with no
 * connection to configure, must never see a three-dot button that opens an
 * empty menu.
 */
function OutletRowActions({ outlet, canManage, canAuthorizeForLivePos, onEdit, onMessagingSettings, onDeliveryConfiguration, onAuthorize, onArchive, onRestore }) {
    const hasConfigure = !!outlet.connection_uuid;
    const hasEdit = canManage && outlet.status === 'active';
    // Messaging preferences remain meaningful operational records even when
    // an outlet is archived, so they are available to any eligible admin
    // rather than being coupled to the identity-edit lifecycle.
    const hasMessagingSettings = canManage;
    const hasAuthorize = canAuthorizeForLivePos && outlet.status === 'active' && !outlet.authorized_for_live_pos;
    const hasArchive = canManage && outlet.status === 'active';
    const hasAnyMenuAction = hasConfigure || hasEdit || hasMessagingSettings || hasAuthorize || hasArchive;
    const canRestore = canManage && outlet.status === 'archived';

    return (
        <div className="flex items-center justify-end gap-2">
            {canRestore && (
                <button
                    type="button"
                    onClick={onRestore}
                    className="inline-flex items-center gap-1 text-brand-600 hover:underline dark:text-brand-400"
                >
                    <RotateCcw className="h-3.5 w-3.5" /> Restore
                </button>
            )}
            {hasAnyMenuAction && (
                <Dropdown>
                    <Dropdown.Trigger>
                        <button
                            type="button"
                            title="Actions"
                            aria-label={`Actions for ${outlet.name}`}
                            className="rounded-soft p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-300 transition duration-150"
                        >
                            <MoreVertical className="h-4 w-4" />
                        </button>
                    </Dropdown.Trigger>
                    <Dropdown.Content align="right" width="56">
                        {hasConfigure && (
                            <Dropdown.Item as="link" href={route('admin.restaurant.connections.show', outlet.connection_uuid)}>
                                <Settings className="mr-2 inline h-4 w-4" />
                                {/* Only NAVIGATES to the connection's detail page — it does not
                                    itself restore anything, so it must never be labeled "Restore
                                    Connection". The actual Restore action, with its confirmation,
                                    lives on that detail page. */}
                                {outlet.connection_state === 'archived' ? 'Manage Archived Connection' : 'Configure'}
                            </Dropdown.Item>
                        )}
                        {hasEdit && (
                            <Dropdown.Item onClick={onEdit}>
                                <Pencil className="mr-2 inline h-4 w-4" />
                                Edit outlet
                            </Dropdown.Item>
                        )}
                        {hasMessagingSettings && (
                            <Dropdown.Item onClick={onMessagingSettings}>
                                <MessageSquare className="mr-2 inline h-4 w-4" />
                                Messaging settings
                            </Dropdown.Item>
                        )}
                        {canManage && (
                            <Dropdown.Item onClick={onDeliveryConfiguration}>
                                <Settings className="mr-2 inline h-4 w-4" />
                                Digital Bill delivery configuration
                            </Dropdown.Item>
                        )}
                        {hasAuthorize && (
                            <Dropdown.Item
                                onClick={onAuthorize}
                                title="Authorize outlet for live Petpooja"
                                aria-label="Authorize outlet for live Petpooja"
                            >
                                <ShieldCheck className="mr-2 inline h-4 w-4" />
                                Authorize
                            </Dropdown.Item>
                        )}
                        {hasArchive && (
                            <>
                                {(hasConfigure || hasEdit || hasMessagingSettings || hasAuthorize) && <Dropdown.Divider />}
                                <Dropdown.Item
                                    onClick={onArchive}
                                    title="Archive outlet"
                                    aria-label="Archive outlet"
                                    className="text-coral-600 hover:bg-coral-50 dark:text-coral-400 dark:hover:bg-coral-950/40"
                                >
                                    <Archive className="mr-2 inline h-4 w-4" />
                                    Archive outlet
                                </Dropdown.Item>
                            </>
                        )}
                    </Dropdown.Content>
                </Dropdown>
            )}
        </div>
    );
}

function DigitalBillDeliveryConfigModal({ outlet, options, onClose }) {
    if (!outlet) return null;
    return <Modal show={!!outlet} onClose={onClose} maxWidth="md"><DigitalBillDeliveryConfigForm key={outlet.uuid} outlet={outlet} options={options} onClose={onClose} /></Modal>;
}

function DigitalBillDeliveryConfigForm({ outlet, options, onClose }) {
    const initialSenderId = outlet.digital_bill_delivery_config?.whatsapp_phone_number_id ?? '';
    const { data, setData, put, processing, errors } = useForm({
        whatsapp_phone_number_id: initialSenderId,
        whatsapp_template_id: outlet.digital_bill_delivery_config?.whatsapp_template_id ?? '',
    });
    const sender = options.senders?.find((item) => Number(item.id) === Number(data.whatsapp_phone_number_id));
    const submit = (event) => {
        event.preventDefault();
        put(route('admin.restaurant.outlets.digital-bill-delivery-config.update', outlet.uuid), { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <form onSubmit={submit}>
            <Modal.Header title={`Digital Bill delivery configuration — ${outlet.name}`} onClose={onClose} />
            <Modal.Body className="space-y-4">
                <p className="text-sm text-neutral-600 dark:text-neutral-400">Configuration is revalidated before any future delivery.</p>
                <Select label="WhatsApp sender" value={data.whatsapp_phone_number_id} onChange={(e) => { setData('whatsapp_phone_number_id', e.target.value); setData('whatsapp_template_id', ''); }} options={[{ value: '', label: 'Select a sender' }, ...(options.senders ?? []).map((item) => ({ value: item.id, label: item.label }))]} />
                <Select label="Approved Utility template" value={data.whatsapp_template_id} onChange={(e) => setData('whatsapp_template_id', e.target.value)} options={[{ value: '', label: sender ? 'Select a Utility template' : 'Select a sender first' }, ...((sender?.templates ?? []).map((item) => ({ value: item.id, label: item.label })))]} disabled={!sender} />
                {(errors.whatsapp_phone_number_id || errors.whatsapp_template_id) && <p className="text-sm text-coral-600 dark:text-coral-400">{errors.whatsapp_phone_number_id || errors.whatsapp_template_id}</p>}
            </Modal.Body>
            <Modal.Footer><Button type="button" variant="secondary" onClick={onClose}>Cancel</Button><Button type="submit" variant="primary" disabled={processing}>Save configuration</Button></Modal.Footer>
        </form>
    );
}

function MessagingSettingsModal({ outlet, onClose }) {
    if (!outlet) return null;

    return (
        <Modal show={!!outlet} onClose={onClose} maxWidth="md">
            <MessagingSettingsForm key={outlet.uuid} outlet={outlet} onClose={onClose} />
        </Modal>
    );
}

function MessagingSettingsForm({ outlet, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        digital_bill_enabled: Boolean(outlet.digital_bill_enabled),
        feedback_request_enabled: Boolean(outlet.feedback_request_enabled),
    });

    const submit = (event) => {
        event.preventDefault();
        put(route('admin.restaurant.outlets.messaging-settings.update', outlet.uuid), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit}>
            <Modal.Header title={`Messaging settings — ${outlet.name}`} onClose={onClose} />
            <Modal.Body className="space-y-4">
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    Both are off by default. Changing these controls does not send anything.
                </p>
                <MessagingToggle
                    id={`digital-bill-${outlet.uuid}`}
                    label="Digital Bill"
                    description="Allow future Digital Bill delivery work for this outlet."
                    checked={data.digital_bill_enabled}
                    onChange={(checked) => setData('digital_bill_enabled', checked)}
                />
                <MessagingToggle
                    id={`feedback-request-${outlet.uuid}`}
                    label="Feedback Request"
                    description="Allow future Feedback Request delivery work for this outlet."
                    checked={data.feedback_request_enabled}
                    onChange={(checked) => setData('feedback_request_enabled', checked)}
                />
                {(errors.digital_bill_enabled || errors.feedback_request_enabled) && (
                    <p className="text-sm text-coral-600 dark:text-coral-400">
                        {errors.digital_bill_enabled || errors.feedback_request_enabled}
                    </p>
                )}
            </Modal.Body>
            <Modal.Footer>
                <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                <Button type="submit" variant="primary" disabled={processing}>Save settings</Button>
            </Modal.Footer>
        </form>
    );
}

function MessagingToggle({ id, label, description, checked, onChange }) {
    return (
        <label htmlFor={id} className="flex cursor-pointer items-start gap-3 rounded-soft border border-neutral-200 p-3 dark:border-neutral-700">
            <input
                id={id}
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-1 h-4 w-4 rounded border-neutral-300 text-brand-600 focus:ring-brand-500"
            />
            <span>
                <span className="block text-sm font-medium text-neutral-900 dark:text-neutral-100">{label}</span>
                <span className="mt-0.5 block text-xs text-neutral-500 dark:text-neutral-400">{description}</span>
            </span>
        </label>
    );
}

function AddOutletModal({ show, onClose, workspaceOptions }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        workspace_id: '',
        name: '',
        address: '',
        timezone: '',
    });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('admin.restaurant.outlets.store'), {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Modal show={show} onClose={close} maxWidth="md">
            <Modal.Header title="Add Outlet Only" onClose={close} />
            <form onSubmit={submit}>
                <Modal.Body className="space-y-4">
                    <Select
                        label="Workspace / restaurant"
                        value={data.workspace_id}
                        onChange={(e) => setData('workspace_id', e.target.value)}
                        options={workspaceOptions}
                        error={errors.workspace_id}
                        required
                    />
                    <Input
                        label="Outlet name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                    <Input
                        label="Address (optional)"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        error={errors.address}
                    />
                    <Input
                        label="Timezone (optional)"
                        placeholder="e.g. Asia/Kolkata"
                        value={data.timezone}
                        onChange={(e) => setData('timezone', e.target.value)}
                        error={errors.timezone}
                    />
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        This creates the outlet with no Petpooja connection. It becomes selectable under this
                        workspace only, from &ldquo;Use an existing outlet&rdquo; on the New Petpooja Connection page.
                    </p>
                </Modal.Body>
                <Modal.Footer>
                    <Button type="button" variant="secondary" onClick={close}>Cancel</Button>
                    <Button type="submit" variant="primary" disabled={processing}>Add outlet</Button>
                </Modal.Footer>
            </form>
        </Modal>
    );
}

/**
 * `key={outlet.uuid}` on the inner form remounts it (and re-seeds useForm's
 * fixed initial state) whenever a DIFFERENT outlet is opened — useForm has no
 * "re-seed defaults" API, so a shared outer form instance would keep showing
 * the first outlet edited this session.
 */
function EditOutletModal({ outlet, onClose }) {
    if (!outlet) return null;

    return (
        <Modal show={!!outlet} onClose={onClose} maxWidth="md">
            <EditOutletForm key={outlet.uuid} outlet={outlet} onClose={onClose} />
        </Modal>
    );
}

function EditOutletForm({ outlet, onClose }) {
    const { data, setData, put, processing, errors } = useForm({
        name: outlet.name ?? '',
        address: outlet.address ?? '',
        timezone: outlet.timezone ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.restaurant.outlets.update', outlet.uuid), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <form onSubmit={submit}>
            <Modal.Header title={`Edit ${outlet.name}`} onClose={onClose} />
            <Modal.Body className="space-y-4">
                <Input
                    label="Outlet name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    required
                />
                <Input
                    label="Address (optional)"
                    value={data.address}
                    onChange={(e) => setData('address', e.target.value)}
                    error={errors.address}
                />
                <Input
                    label="Timezone (optional)"
                    value={data.timezone}
                    onChange={(e) => setData('timezone', e.target.value)}
                    error={errors.timezone}
                />
            </Modal.Body>
            <Modal.Footer>
                <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                <Button type="submit" variant="primary" disabled={processing}>Save changes</Button>
            </Modal.Footer>
        </form>
    );
}

function ArchiveOutletModal({ outlet, onClose }) {
    const [processing, setProcessing] = useState(false);

    if (!outlet) return null;

    const confirmArchive = () => {
        setProcessing(true);
        router.post(route('admin.restaurant.outlets.archive', outlet.uuid), {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Modal show={!!outlet} onClose={onClose} maxWidth="sm">
            <Modal.Header title={`Archive ${outlet.name}?`} onClose={onClose} />
            <Modal.Body>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    The outlet will no longer be selectable for a new Petpooja connection. An outlet with an active
                    connection cannot be archived — disconnect or archive its connection first.
                </p>
            </Modal.Body>
            <Modal.Footer>
                <Button variant="secondary" onClick={onClose}>Cancel</Button>
                <Button variant="danger" disabled={processing} onClick={confirmArchive}>Archive outlet</Button>
            </Modal.Footer>
        </Modal>
    );
}

/**
 * Gate 5 of the six-gate Petpooja live activation invariant
 * ("outlet-specific authorization") — the real, auditable admin action that
 * this concept requires, reachable through this button and its own
 * permission-gated route rather than a database-only field nobody sets.
 * There is deliberately no "revoke" counterpart in this slice: archiving
 * the outlet already blocks everything downstream, and authorization is
 * meant to persist through an archive/restore cycle exactly like a
 * connection's token does.
 */
function AuthorizeOutletModal({ outlet, onClose }) {
    const [processing, setProcessing] = useState(false);

    if (!outlet) return null;

    const confirmAuthorize = () => {
        setProcessing(true);
        router.post(route('admin.restaurant.outlets.authorize-live-pos', outlet.uuid), {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Modal show={!!outlet} onClose={onClose} maxWidth="sm">
            <Modal.Header title={`Authorize ${outlet.name} for a live Petpooja connection?`} onClose={onClose} />
            <Modal.Body>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    Confirms this physical outlet has been verified and may go live. This is one of six required
                    checks before a live Petpooja connection on this outlet can be activated — the others (Terms,
                    DPA and restaurant declaration acceptance, and a connected WhatsApp Business Account) are
                    tracked at the workspace level, not here.
                </p>
            </Modal.Body>
            <Modal.Footer>
                <Button variant="secondary" onClick={onClose}>Cancel</Button>
                <Button variant="primary" disabled={processing} onClick={confirmAuthorize}>Authorize outlet</Button>
            </Modal.Footer>
        </Modal>
    );
}

function RestoreOutletModal({ outlet, onClose }) {
    const [processing, setProcessing] = useState(false);

    if (!outlet) return null;

    const confirmRestore = () => {
        setProcessing(true);
        router.post(route('admin.restaurant.outlets.restore', outlet.uuid), {}, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <Modal show={!!outlet} onClose={onClose} maxWidth="sm">
            <Modal.Header title={`Restore ${outlet.name}?`} onClose={onClose} />
            <Modal.Body>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    The outlet becomes active again and selectable for a new Petpooja connection. This does NOT
                    resume ingestion for any Petpooja connection this outlet had — a connection archived along with
                    it stays archived until you restore it separately from the Connections directory.
                </p>
            </Modal.Body>
            <Modal.Footer>
                <Button variant="secondary" onClick={onClose}>Cancel</Button>
                <Button variant="primary" disabled={processing} onClick={confirmRestore}>Restore outlet</Button>
            </Modal.Footer>
        </Modal>
    );
}
