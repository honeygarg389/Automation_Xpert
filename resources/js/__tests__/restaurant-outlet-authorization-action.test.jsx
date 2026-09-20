import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS — the Outlets directory's compact "Actions" menu
 * (Configure, Edit outlet, Authorize, Archive outlet), which a table-row
 * refactor collapsed from four separate inline controls into one three-dot
 * trigger using the project's shared `Dropdown` component.
 *
 * History, preserved because each step is still a live regression risk:
 *   1. A visual review found every row showing "Not authorized" with NO
 *      visible action for a Super Admin to do anything about it (Gate 5 of
 *      the six-gate Petpooja live activation invariant) — the permission
 *      check itself was correct, but only an HTTP-level Inertia prop test
 *      existed, and that cannot prove a React component actually RENDERS a
 *      button for the permission it is supposed to gate on.
 *   2. A follow-up compact-table refinement shortened "Authorize for Live"
 *      to "Authorize" and replaced the red "Archive" text action with an
 *      icon-only button.
 *   3. THIS refactor collapsed both of those, plus Configure and Edit, into
 *      one "Actions" column/menu. Every item keeps its OWN pre-existing
 *      visibility condition, click handler, confirmation modal and route —
 *      only the presentation moved. This file mounts the real page
 *      component with a real (not merely assumed) permission list, opens
 *      the real menu, and reads the real rendered items — the same
 *      `permissions.includes(...)` / `outlet.status` / `outlet.authorized_for_live_pos`
 *      checks the component itself performs.
 *
 * ⚠️ `grantedPermissions` is MUTABLE, following the established
 * smartqr-inventory-detail.test.jsx convention: a FIXED permission list can
 * only ever prove the granted case, which is the half that passes whether or
 * not the gate exists at all.
 */

let grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { permissions: grantedPermissions }, flash: {} },
        url: '/admin/restaurant/outlets',
    }),
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (initial) => ({ data: initial, setData: vi.fn(), post: vi.fn(), put: vi.fn(), processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn() }),
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => {
        const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
        const parts = k.split('.');
        let node = en[parts[0]];
        for (let i = 1; i < parts.length && node != null; i++) node = node[parts[i]];
        if (typeof node !== 'string') return k;
        return o ? node.replace(/\{\{(\w+)\}\}/g, (_, n) => o[n] ?? '') : node;
    } }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import Index from '@/Pages/Admin/Restaurant/Outlets/Index';

const makeOutlet = (over = {}) => ({
    uuid: 'outlet-uuid-1',
    workspace_name: 'Test Workspace',
    name: 'Downtown Branch',
    address: '1 Main St',
    timezone: 'Asia/Kolkata',
    status: 'active',
    connection_state: 'not_connected',
    connection_uuid: null,
    authorized_for_live_pos: false,
    digital_bill_enabled: false,
    feedback_request_enabled: false,
    ...over,
});

const renderPage = (outletsOver = {}) =>
    render(
        <Index
            outlets={{ data: [makeOutlet(outletsOver)], links: [], current_page: 1, last_page: 1, total: 1 }}
            workspaces={[{ id: 1, name: 'Test Workspace', client_name: null }]}
            filters={{ search: '', workspace_id: '', status: 'active' }}
        />
    );

/** Opens the row's Actions menu and returns the (now-mounted) menu content region. */
async function openActionsMenu(row) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(within(row).getByRole('button', { name: /^Actions for /i }));
    return user;
}

describe('Outlets directory — Actions column', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('renders "Actions" as the final column header', () => {
        renderPage();

        expect(screen.getByRole('columnheader', { name: 'Actions' })).toBeTruthy();
    });

    it('renders exactly one accessible Actions trigger for an eligible row, with the outlet name in its accessible name', () => {
        renderPage();

        const row = screen.getByText('Downtown Branch').closest('tr');
        const triggers = within(row).getAllByRole('button', { name: 'Actions for Downtown Branch' });

        expect(triggers).toHaveLength(1);
        expect(triggers[0]).toHaveAttribute('title', 'Actions');
    });

    /**
     * The trigger must not appear at all for a row with zero possible
     * actions — an admin with no relevant permission looking at an
     * unconnected, active outlet has nothing this menu could do, and a
     * three-dot button that opens empty is worse than no button.
     */
    it('hides the Actions trigger entirely for a row with no available actions', () => {
        grantedPermissions = ['view_pos_connections'];
        // active, no connection, no manage/authorize permission: Configure,
        // Edit, Authorize and Archive are all individually ineligible.
        renderPage({ connection_uuid: null, status: 'active' });

        const row = screen.getByText('Downtown Branch').closest('tr');
        expect(within(row).queryByRole('button', { name: /^Actions for /i })).toBeNull();
    });

    /** Restore was explicitly out of scope for the Actions-menu refactor and must remain its own inline control. */
    it('keeps Restore as a separate, always-visible control alongside the Actions menu for an archived outlet', () => {
        renderPage({ status: 'archived' });

        const row = screen.getByText('Downtown Branch').closest('tr');
        expect(within(row).getByRole('button', { name: /^Restore$/i })).toBeTruthy();
    });
});

describe('Outlets directory — Configure item', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('shows Configure only when the outlet has a connection, and links to it', async () => {
        renderPage({ connection_uuid: 'conn-uuid-1', connection_state: 'connected' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        const configureLink = screen.getByRole('link', { name: 'Configure' });
        expect(configureLink).toHaveAttribute('href', expect.stringContaining('conn-uuid-1'));
    });

    it('reads "Manage Archived Connection" instead of "Configure" when the connection itself is archived', async () => {
        renderPage({ connection_uuid: 'conn-uuid-1', connection_state: 'archived' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.getByRole('link', { name: 'Manage Archived Connection' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Configure' })).toBeNull();
    });

    it('is absent when the outlet has no connection at all', async () => {
        renderPage({ connection_uuid: null });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByText('Configure')).toBeNull();
        expect(screen.queryByText('Manage Archived Connection')).toBeNull();
    });
});

describe('Outlets directory — Edit outlet item', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('shows Edit outlet for an active outlet when the admin can manage', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.getByRole('button', { name: 'Edit outlet' })).toBeTruthy();
    });

    it('is hidden without manage_pos_connections, same rule as before the menu refactor', async () => {
        grantedPermissions = ['view_pos_connections'];
        // Give the row a connection so the Actions trigger still has
        // something to show (Configure), isolating Edit's own condition.
        renderPage({ connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Edit outlet' })).toBeNull();
    });

    it('is hidden for an archived outlet, same rule as before the menu refactor', async () => {
        renderPage({ status: 'archived', connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Edit outlet' })).toBeNull();
    });
});

describe('Outlets directory — Messaging settings action', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('shows the independent Messaging settings action and its no-send modal only to an eligible admin', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        const user = await openActionsMenu(row);

        await user.click(screen.getByRole('button', { name: 'Messaging settings' }));

        expect(screen.getByText('Messaging settings — Downtown Branch')).toBeTruthy();
        expect(screen.getByText('Both are off by default. Changing these controls does not send anything.')).toBeTruthy();
        expect(screen.getByRole('checkbox', { name: /Digital Bill/i })).not.toBeChecked();
        expect(screen.getByRole('checkbox', { name: /Feedback Request/i })).not.toBeChecked();
    });

    it('does not show Messaging settings to an admin without manage_pos_connections', async () => {
        grantedPermissions = ['view_pos_connections'];
        renderPage({ connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Messaging settings' })).toBeNull();
    });
});

describe('Outlets directory — Authorize item', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('shows Authorize for an active, unauthorized outlet when the admin holds authorize_pos_outlets', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        const item = screen.getByRole('button', { name: 'Authorize outlet for live Petpooja' });
        expect(item).toBeTruthy();
        expect(item).toHaveAttribute('title', 'Authorize outlet for live Petpooja');
        expect(within(row).getByText('Not authorized')).toBeTruthy();
    });

    /**
     * ⚠️ "Authorize for Live" must never return — the visible text is the
     * short "Authorize", the fuller description lives only in the
     * accessible name / title, exactly as the prior refinement established.
     */
    it('renders the short "Authorize" label and never "Authorize for Live"', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.getByText('Authorize')).toBeTruthy();
        expect(screen.queryByText(/Authorize for Live/i)).toBeNull();
    });

    /**
     * ⚠️ THE NEGATIVE CONTROL. A Super Admin missing this specific
     * permission (e.g. because PermissionSeeder/RoleSeeder were updated in
     * code but never re-synced against a real environment's database — see
     * RestaurantOutletAdminTest's seeding_permissions_then_roles_grants...
     * test) must not see the action, proving the item really is conditioned
     * on the permission and not always rendered.
     */
    it('hides Authorize when the admin does not hold authorize_pos_outlets', async () => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections'];
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Authorize outlet for live Petpooja' })).toBeNull();
        expect(screen.queryByText('Authorize')).toBeNull();
        expect(within(row).getByText('Not authorized')).toBeTruthy();
    });

    it('hides Authorize and shows an Authorized badge once the outlet is already authorized', async () => {
        renderPage({ authorized_for_live_pos: true });
        const row = screen.getByText('Downtown Branch').closest('tr');
        expect(within(row).getByText('Authorized')).toBeTruthy();
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Authorize outlet for live Petpooja' })).toBeNull();
    });

    it('hides Authorize for an archived outlet even with the permission', async () => {
        renderPage({ status: 'archived', connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Authorize outlet for live Petpooja' })).toBeNull();
    });

    /**
     * Clicking the item opens the confirmation modal, which must state both
     * halves of what the action does: it enables live activation
     * eligibility for THIS outlet, and it does NOT itself start Petpooja
     * ingestion — activation is still a separate, explicit step elsewhere.
     * Unchanged by the menu refactor — same modal, same route.
     */
    it('opens the same confirmation modal explaining what authorization does and does not do', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        const user = await openActionsMenu(row);
        await user.click(screen.getByRole('button', { name: 'Authorize outlet for live Petpooja' }));

        expect(screen.getByText(/Authorize Downtown Branch for a live Petpooja connection\?/i)).toBeTruthy();
        const modalBody = screen.getByText(/one of six required checks/i);
        expect(modalBody.textContent).toMatch(/before a live Petpooja connection.*can be activated/i);
    });
});

describe('Outlets directory — Archive outlet item', () => {
    beforeEach(() => {
        grantedPermissions = ['view_pos_connections', 'manage_pos_connections', 'authorize_pos_outlets'];
    });

    it('is present inside the menu with its accessible name and title, styled as destructive', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        const archiveItem = screen.getByRole('button', { name: 'Archive outlet' });
        expect(archiveItem).toBeTruthy();
        expect(archiveItem).toHaveAttribute('title', 'Archive outlet');
        expect(archiveItem.className).toMatch(/coral/);
    });

    it('is gated on manage_pos_connections, same rule as before the menu refactor', async () => {
        grantedPermissions = ['view_pos_connections'];
        renderPage({ connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Archive outlet' })).toBeNull();
    });

    it('is hidden for an archived outlet, same rule as before the menu refactor', async () => {
        renderPage({ status: 'archived', connection_uuid: 'conn-uuid-1' });
        const row = screen.getByText('Downtown Branch').closest('tr');
        await openActionsMenu(row);

        expect(screen.queryByRole('button', { name: 'Archive outlet' })).toBeNull();
    });

    it('opens the same existing archive confirmation modal', async () => {
        renderPage();
        const row = screen.getByText('Downtown Branch').closest('tr');
        const user = await openActionsMenu(row);
        await user.click(screen.getByRole('button', { name: 'Archive outlet' }));

        expect(screen.getByText('Archive Downtown Branch?')).toBeTruthy();
        expect(screen.getByText(/An outlet with an active connection cannot be archived/i)).toBeTruthy();
        // The modal's own destructive confirm button — a distinct element
        // from the menu item that opened it, both sharing the name.
        expect(screen.getAllByRole('button', { name: 'Archive outlet' })).toHaveLength(2);
    });
});
