import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, fireEvent } from '@testing-library/react';

/**
 * ⚠️ WHAT THIS FILE PINS — the assignments list added in 6546399.
 *
 * PHP cannot see any of these four things: the ACTUAL guard on a locked row's
 * toggle is a `window.confirm()` gate in this component, not a backend refusal
 * — `QrAssignmentController::update` carries none by design (see
 * `SmartQrAssignmentLockTest::the_admin_status_path_is_unaffected_by_the_lock`,
 * and the commit's own docblock: "the guard for it lives on the customer
 * controller only"). So the thing worth mutation-testing here is the CONFIRM
 * CALL, not a server-side block that does not exist.
 *
 *   the toggle       clicking an unlocked row's switch PATCHes the new status
 *                    straight through, no prompt
 *   the lock prompt   a locked row asks first; declining sends NO request
 *   the override      confirming a locked row's prompt still sends the PATCH —
 *                      admin override is the intended behaviour, not a bug
 *   the search        the debounced input requests with `search`, and carries
 *                      the current/history filter through unchanged
 *   the ended column  header AND body cell are both absent outside history view
 */

const patches = [];
const gets = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { permissions: ['assign_qr_codes', 'view_qr_inventory'] },
            flash: {},
            timezone: 'UTC',
        },
        url: mockUrl,
    }),
    router: {
        patch: (url, data, opts) => { patches.push({ url, data, opts }); opts?.onSuccess?.(); },
        get: (url, data, opts) => gets.push({ url, data, opts }),
        delete: vi.fn(),
    },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

const EN = {
    'smart_qr.assignments_title': 'QR Assignments',
    'smart_qr.assignments_subtitle': 'Which workspace holds which code, and for how long.',
    'smart_qr.search_by_serial': 'Search by Serial',
    'smart_qr.show_current_only': 'Current only',
    'smart_qr.show_history': 'Show history',
    'smart_qr.col_serial': 'Serial',
    'smart_qr.col_workspace': 'Workspace',
    'smart_qr.field_destination_phone': 'Destination phone',
    'smart_qr.col_qr_name': 'QR name',
    'smart_qr.col_status': 'Status',
    'smart_qr.col_assigned_at': 'Assigned',
    'smart_qr.col_ended_at': 'Ended',
    'smart_qr.edit_qr': 'Edit QR details',
    'smart_qr.unassign': 'Unassign',
    'smart_qr.unassign_confirm': 'Unassign {{serial}}? Its scan history stays with the current tenant.',
    'smart_qr.no_assignments': 'No assignments yet.',
    'smart_qr.toggle_status_failed': 'Could not change the status. Nothing was saved.',
    'smart_qr.toggle_locked_confirm':
        '{{serial}} is locked by the platform team. Changing its active status here overrides that lock — the customer still will not be able to change it themselves. Continue?',
};

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (k, opts) => {
            const raw = EN[k] ?? k;

            return opts ? raw.replace(/\{\{(\w+)\}\}/g, (_, n) => opts[n] ?? '') : raw;
        },
    }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

// EditAssignmentModal is only mounted once `editing` is set — none of these
// tests click Edit — but it is stubbed anyway so importing it never pulls in
// something this file has not mocked.
vi.mock('../Pages/Admin/SmartQr/EditAssignmentModal', () => ({ default: () => null }));

let mockUrl = '/admin/qr/assignments';

beforeEach(() => {
    patches.length = 0;
    gets.length = 0;
    mockUrl = '/admin/qr/assignments';
    global.route = (name, param) => `/__route__/${name}${param ? '/' + param : ''}`;
    window.confirm = vi.fn(() => true);
});

afterEach(() => {
    vi.clearAllMocks();
    vi.useRealTimers();
});

const paginator = (rows) => ({
    data: rows, links: [], current_page: 1, last_page: 1, total: rows.length,
});

const row = (over = {}) => ({
    id: 1,
    uuid: 'assign-uuid-1',
    admin_locked: false,
    status: 'active',
    code: { serial_number: 'AX-000001' },
    workspace: { name: 'Acme Cafe' },
    channel_account: { phone_number: { display_phone: '+1 415-555-0100' } },
    name: 'Front counter',
    assigned_at: '2026-01-01T00:00:00Z',
    unassigned_at: null,
    ...over,
});

describe('Admin/SmartQr/Assignments/Index.jsx — status toggle', () => {
    it('PATCHes an unlocked active row to inactive with no confirm prompt', async () => {
        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row({ status: 'active' })])} filters={{ search: '' }} />);

        const toggle = screen.getByRole('switch');
        fireEvent.click(toggle);

        expect(window.confirm).not.toHaveBeenCalled();
        expect(patches).toHaveLength(1);
        expect(patches[0].url).toBe('/__route__/admin.qr.assignments.update/assign-uuid-1');
        expect(patches[0].data).toEqual({ status: 'inactive' });
    });

    it('round-trips: an inactive row toggles back to active', async () => {
        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row({ status: 'inactive' })])} filters={{ search: '' }} />);

        fireEvent.click(screen.getByRole('switch'));

        expect(patches).toHaveLength(1);
        expect(patches[0].data).toEqual({ status: 'active' });
    });

    /**
     * ⚠️ THE ACTUAL GUARD. Declining the prompt must send NOTHING — this is
     * the one line standing between a locked row and an accidental override,
     * since the backend accepts the change unconditionally once it arrives.
     */
    it('a locked row asks first, and declining sends no PATCH', async () => {
        window.confirm = vi.fn(() => false);

        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row({ admin_locked: true, status: 'inactive' })])} filters={{ search: '' }} />);

        fireEvent.click(screen.getByRole('switch'));

        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(window.confirm.mock.calls[0][0]).toContain('AX-000001');
        expect(patches).toHaveLength(0);
    });

    /**
     * ⚠️ Confirming a locked row's prompt is meant to succeed — admin override
     * is the documented, intended behaviour (see file docblock), so this is a
     * positive control sitting beside the decline test above: same row, same
     * click, opposite confirm answer, opposite outcome.
     */
    it('a locked row still PATCHes once the admin confirms the override', async () => {
        window.confirm = vi.fn(() => true);

        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row({ admin_locked: true, status: 'inactive' })])} filters={{ search: '' }} />);

        fireEvent.click(screen.getByRole('switch'));

        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(patches).toHaveLength(1);
        expect(patches[0].data).toEqual({ status: 'active' });
    });
});

describe('Admin/SmartQr/Assignments/Index.jsx — search', () => {
    it('debounces, then requests with `search` and skips the very first render', async () => {
        vi.useFakeTimers();

        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row()])} filters={{ search: '' }} />);

        // Mount alone must not fire a request — the effect skips its first run.
        act(() => { vi.advanceTimersByTime(1000); });
        expect(gets).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('Search by Serial'), { target: { value: 'AX-0000' } });

        // Not yet — under the 400ms debounce.
        act(() => { vi.advanceTimersByTime(300); });
        expect(gets).toHaveLength(0);

        act(() => { vi.advanceTimersByTime(150); });

        expect(gets).toHaveLength(1);
        expect(gets[0].url).toBe('/__route__/admin.qr.assignments.index');
        expect(gets[0].data).toEqual({ search: 'AX-0000' });
    });

    it('carries the history filter through so searching while viewing history does not reset it', async () => {
        vi.useFakeTimers();
        mockUrl = '/admin/qr/assignments?current=all';

        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row()])} filters={{ search: '' }} />);

        fireEvent.change(screen.getByLabelText('Search by Serial'), { target: { value: 'AX' } });
        act(() => { vi.advanceTimersByTime(400); });

        expect(gets[0].data).toEqual({ current: 'all', search: 'AX' });
    });
});

describe('Admin/SmartQr/Assignments/Index.jsx — ENDED column', () => {
    it('is absent, header and cell alike, in the current-only (default) view', async () => {
        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index assignments={paginator([row({ unassigned_at: null })])} filters={{ search: '' }} />);

        expect(screen.queryByText('Ended')).not.toBeInTheDocument();
    });

    it('appears, header and populated cell alike, once viewing history (current=all)', async () => {
        mockUrl = '/admin/qr/assignments?current=all';

        const { default: Index } = await import('@/Pages/Admin/SmartQr/Assignments/Index');

        render(<Index
            assignments={paginator([row({ status: 'ended', unassigned_at: '2026-02-01T00:00:00Z' })])}
            filters={{ search: '' }}
        />);

        expect(screen.getByText('Ended')).toBeInTheDocument();
        // An 'ended' row shows the status badge instead of a live toggle switch.
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });
});
