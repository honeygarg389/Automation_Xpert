import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, within, fireEvent } from '@testing-library/react';
import fs from 'fs';
import { formatDateTz } from '@/Utils/datetime';

/**
 * ⚠️ WHAT THIS FILE PINS — the QR Management > Dashboard.
 *
 *   the 8 tiles      each label paired with ITS OWN value, not merely "both
 *                    strings appear somewhere on the page" — every seeded
 *                    number is distinct, so a mispaired label/value fails
 *   the quick links  both anchors point at the real index routes
 *   the three panels populated rows AND the empty state for each, because the
 *                    empty branch is a different code path and ships unseen
 *   the i18n keys    resolved through the REAL en.json (see the t() mock), so a
 *                    missing or misspelled key fails here as a raw
 *                    `smart_qr.…` string rather than rendering in production
 *
 * ⚠️ THIS FILE READS en.json OFF DISK — IT CANNOT CATCH THE STALE-CACHE BUG.
 * The app serves labels from `I18nFileService`'s cached snapshot, not from the
 * file; those two disagree until `invalidateCache()` runs. So a green run here
 * proves the KEYS EXIST, never that the running page renders them. That gap is
 * exactly how the raw-key regression reached the browser four times — see the
 * i18n note in CLAUDE.md. Load the page to check the other half.
 */

let mockTimezone = 'UTC';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { permissions: [] }, flash: {}, timezone: mockTimezone }, url: '/admin/qr/dashboard' }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

/**
 * ⚠️ Resolves against the real en.json and FALLS BACK TO THE KEY, deliberately.
 * A stubbed dictionary would make every assertion below pass against a key that
 * does not exist — which is the failure this file is partly here to catch.
 */
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (k, o) => {
            const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
            const parts = k.split('.');
            let node = en[parts[0]];
            for (let i = 1; i < parts.length && node != null; i++) node = node[parts[i]];
            if (typeof node !== 'string') return k;
            return o ? node.replace(/\{\{(\w+)\}\}/g, (_, n) => o[n] ?? '') : node;
        },
    }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

/**
 * ⚠️ THE CHART WRAPPERS ARE STUBBED, AND NOT TO AVOID WORK.
 *
 * recharts draws inside a ResponsiveContainer, which measures its parent — in
 * jsdom that measures 0x0, so the real components render NOTHING and every
 * assertion about a slice, legend or axis would pass or fail for reasons
 * unrelated to this page. Measured: the legend never appeared in the DOM.
 *
 * Capturing the props instead tests the thing this page is actually
 * responsible for — the SHAPE and LABELS of the data handed to the chart —
 * and leaves recharts' own rendering to recharts.
 */
vi.mock('@/Components/Charts', () => ({
    DonutChart: ({ data }) => <div data-testid="donut" data-payload={JSON.stringify(data)} />,
    LineChart: ({ data }) => <div data-testid="line" data-payload={JSON.stringify(data)} />,
}));

const STATS = {
    total_codes: 142,
    available: 81,
    active: 37,
    total_batches: 9,
    printed: 55,
    assigned: 40,
    inactive: 3,
    retired: 12,
};

const BATCH = {
    uuid: 'batch-uuid-1',
    batch_name: 'Business Kit August',
    batch_number: 'AX-BK-001',
    status: 'generated',
    quantity: 500,
    created_at: '2026-08-27T05:03:09Z',
};

const ASSIGNMENT = {
    uuid: 'assign-uuid-1',
    workspace_name: 'Acme Cafe',
    serial_number: 'AX-000042',
    name: 'Front counter',
    assigned_at: '2026-09-01T10:15:00Z',
};

const ACTIVITY = {
    id: 7,
    action: 'smart_qr.batch_created',
    actor_name: 'Super Admin',
    meta: { batch_number: 'AX-BK-001' },
    created_at: '2026-09-03T08:00:00Z',
};

const EXPIRING = {
    uuid: 'expiring-uuid-1',
    workspace_name: 'Bloom Florist',
    serial_number: 'AX-000077',
    name: 'Window sticker',
    expires_at: '2026-09-08T12:00:00Z',
};

const ENDED = {
    uuid: 'ended-uuid-1',
    workspace_name: 'Corner Deli',
    serial_number: 'AX-000055',
    assigned_at: '2026-07-07T09:00:00Z',
    unassigned_at: '2026-07-14T09:00:00Z',
    held_days: 7,
};

/** 30 zero-filled days, with the last two carrying scans. */
const SCAN_VOLUME = Array.from({ length: 30 }, (_, i) => {
    const d = new Date(Date.UTC(2026, 7, 6));
    d.setUTCDate(d.getUTCDate() + i);
    const last2 = i >= 28;

    return {
        date: d.toISOString().slice(0, 10),
        scans: last2 ? 4 + i - 28 : 0,
        unique_scans: last2 ? 2 : 0,
    };
});

const props = (over = {}) => ({
    stats: STATS,
    recentBatches: [BATCH],
    recentAssignments: [ASSIGNMENT],
    nearExpiring: [EXPIRING],
    recentlyEnded: [ENDED],
    lockedCodes: 3,
    topWorkspaces: [
        { name: 'Acme Cafe', value: 6, is_other: false },
        { name: 'Bloom Florist', value: 2, is_other: false },
    ],
    scanVolume: SCAN_VOLUME,
    recentActivity: [ACTIVITY],
    ...over,
});

/** The data each stubbed chart was handed, parsed back out. */
const donutPayload = () => JSON.parse(screen.getByTestId('donut').dataset.payload);
const linePayload = () => JSON.parse(screen.getByTestId('line').dataset.payload);

const load = async () => (await import('@/Pages/Admin/SmartQr/Dashboard')).default;

/**
 * ⚠️ THE CONSOLE SPY IS INSTALLED FOR EVERY TEST, NOT JUST THE SMOKE TEST, AND
 * THAT IS NOT BELT-AND-BRACES — a single smoke test cannot catch these.
 *
 * React dedupes warnings like "Each child in a list should have a unique key"
 * to once per component per run, so the warning fires on whichever test renders
 * the list FIRST and is silent everywhere after. A spy installed only inside a
 * final smoke test therefore sees a clean console no matter how broken the
 * markup is — measured: removing `key=` from the activity list left that test
 * passing. Spying from the first render and asserting per test is what makes
 * the check able to fail at all.
 */
let consoleErrors = [];
let consoleWarnings = [];
let errSpy;
let warnSpy;

beforeEach(() => {
    mockTimezone = 'UTC';
    global.route = (name) => `/__route__/${name}`;

    consoleErrors = [];
    consoleWarnings = [];
    errSpy = vi.spyOn(console, 'error').mockImplementation((...a) => consoleErrors.push(a.join(' ')));
    warnSpy = vi.spyOn(console, 'warn').mockImplementation((...a) => consoleWarnings.push(a.join(' ')));
});

afterEach(() => {
    const errors = [...consoleErrors];
    const warnings = [...consoleWarnings];

    errSpy.mockRestore();
    warnSpy.mockRestore();
    vi.clearAllMocks();

    expect(errors, `console.error during this test: ${errors.join(' | ')}`).toEqual([]);
    expect(warnings, `console.warn during this test: ${warnings.join(' | ')}`).toEqual([]);
});

describe('Admin/SmartQr/Dashboard — stat tiles', () => {
    /**
     * ⚠️ Asserts the label and value are in the SAME tile. Every seeded number
     * is distinct, so pairing "Retired" with the Printed count would fail —
     * which a page-wide getByText for each string separately would not.
     */
    it('renders all 8 tiles, each label paired with its own value', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        const expected = [
            ['Total QR Codes', '142'],
            ['Available', '81'],
            ['Active', '37'],
            ['Total Batches', '9'],
            ['Printed', '55'],
            ['Assigned', '40'],
            ['Inactive', '3'],
            ['Retired', '12'],
        ];

        expect(expected).toHaveLength(8);

        // ⚠️ SCOPED TO THE STAT GRID, not the page. "Assigned" is now ALSO the
        // Recently Assigned panel's `col_assigned_at` header, so a page-wide
        // getByText would match two elements and throw. Anchoring on the one
        // unambiguous label and walking up to the grid keeps the query honest
        // without asserting on brittle class names.
        const grid = screen.getByText('Total QR Codes').closest('.grid');
        expect(grid, 'stat grid not found').not.toBeNull();

        for (const [label, value] of expected) {
            const tile = within(grid).getByText(label).closest('.rounded-xl');
            expect(tile, `no tile found for "${label}"`).not.toBeNull();
            expect(within(tile).getByText(value)).toBeInTheDocument();
        }
    });

    /** The labels come from en.json, so a missing key shows as the raw string. */
    it('renders translated tile labels, not raw i18n keys', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props()} />);

        expect(container.textContent).not.toMatch(/smart_qr\.stat_/);
        expect(container.textContent).not.toMatch(/smart_qr\.dashboard_/);
    });
});

describe('Admin/SmartQr/Dashboard — header and quick actions', () => {
    it('renders the translated title and subtitle', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getAllByText('QR Dashboard').length).toBeGreaterThan(0);
        expect(screen.getByText(/An overview of every QR code, batch and assignment/)).toBeInTheDocument();
    });

    it('offers both quick actions, each pointing at its real index route', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getByText('Create Batch').closest('a'))
            .toHaveAttribute('href', '/__route__/admin.qr.batches.index');
        expect(screen.getByText('View Inventory').closest('a'))
            .toHaveAttribute('href', '/__route__/admin.qr.inventory.index');
    });

    it('gives each list panel a "View all" link to its own index', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        const hrefs = screen.getAllByText('View all').map((el) => el.closest('a').getAttribute('href'));

        expect(hrefs).toContain('/__route__/admin.qr.batches.index');
        expect(hrefs).toContain('/__route__/admin.qr.assignments.index');
    });
});

describe('Admin/SmartQr/Dashboard — Recent Batches', () => {
    it('renders name, number, quantity, status badge and formatted date', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getByText('Business Kit August')).toBeInTheDocument();
        expect(screen.getByText('AX-BK-001')).toBeInTheDocument();
        expect(screen.getByText('500')).toBeInTheDocument();

        // BatchStatusBadge maps `generated` through smart_qr.batch_status.*
        expect(screen.getByText('Generated')).toBeInTheDocument();

        // ⚠️ Asserted as FORMATTED, and proven not to be the raw ISO string —
        // a date passed straight through would still "contain 2026".
        const shown = formatDateTz(BATCH.created_at, 'UTC');
        expect(shown).not.toBe(BATCH.created_at);
        expect(screen.getByText(shown)).toBeInTheDocument();
    });

    it('shows the empty state instead of a table when there are no batches', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({ recentBatches: [] })} />);

        expect(screen.getByText('No batches yet.')).toBeInTheDocument();
        expect(screen.queryByText('Business Kit August')).not.toBeInTheDocument();
    });
});

describe('Admin/SmartQr/Dashboard — Recently Assigned', () => {
    it('renders serial, workspace and formatted assignment date', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getByText('AX-000042')).toBeInTheDocument();
        expect(screen.getByText('Acme Cafe')).toBeInTheDocument();
        expect(screen.getByText(formatDateTz(ASSIGNMENT.assigned_at, 'UTC'))).toBeInTheDocument();
    });

    /** ⚠️ Both columns are `?? '—'`; a null must not render "null". */
    it('falls back to a dash for a missing serial or workspace', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            recentAssignments: [{ ...ASSIGNMENT, serial_number: null, workspace_name: null }],
        })} />);

        expect(screen.getAllByText('—')).toHaveLength(2);
        expect(screen.queryByText('null')).not.toBeInTheDocument();
    });

    it('shows the empty state when nothing has been assigned', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({ recentAssignments: [] })} />);

        expect(screen.getByText('No assignments yet.')).toBeInTheDocument();
        expect(screen.queryByText('AX-000042')).not.toBeInTheDocument();
    });
});

describe('Admin/SmartQr/Dashboard — Near Expire', () => {
    it('renders serial, workspace and the formatted expiry date', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getByText('AX-000077')).toBeInTheDocument();
        expect(screen.getByText('Bloom Florist')).toBeInTheDocument();

        const shown = formatDateTz(EXPIRING.expires_at, 'UTC');
        expect(shown).not.toBe(EXPIRING.expires_at);
        expect(screen.getByText(shown)).toBeInTheDocument();
    });

    /** The panel title and column header come from the 3 keys added with it. */
    it('renders its translated title and Expires column, not raw keys', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props()} />);

        expect(screen.getByText('Near Expire')).toBeInTheDocument();
        expect(screen.getByText('Expires')).toBeInTheDocument();
        expect(container.textContent).not.toMatch(/smart_qr\.(near_expire|col_expires|no_near_expire)/);
    });

    /**
     * ⚠️ Rendered in the order the controller supplies (soonest first) — the
     * component must not re-sort. The backend proves the ORDERING; this proves
     * the component preserves it rather than quietly reordering by uuid or key.
     */
    it('preserves the soonest-first order it is given', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            nearExpiring: [
                { ...EXPIRING, uuid: 'e1', serial_number: 'AX-SOON-1', expires_at: '2026-09-06T12:00:00Z' },
                { ...EXPIRING, uuid: 'e2', serial_number: 'AX-SOON-2', expires_at: '2026-09-09T12:00:00Z' },
                { ...EXPIRING, uuid: 'e3', serial_number: 'AX-SOON-3', expires_at: '2026-09-11T12:00:00Z' },
            ],
        })} />);

        const rendered = screen.getAllByText(/^AX-SOON-\d$/).map((el) => el.textContent);

        expect(rendered).toEqual(['AX-SOON-1', 'AX-SOON-2', 'AX-SOON-3']);
    });

    it('falls back to a dash for a missing serial or workspace', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            nearExpiring: [{ ...EXPIRING, serial_number: null, workspace_name: null }],
            recentAssignments: [],
        })} />);

        expect(screen.getAllByText('—')).toHaveLength(2);
        expect(screen.queryByText('null')).not.toBeInTheDocument();
    });

    it('shows the translated empty state when nothing expires within the window', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props({ nearExpiring: [] })} />);

        expect(screen.getByText('Nothing expiring in the next 7 days.')).toBeInTheDocument();
        expect(screen.queryByText('AX-000077')).not.toBeInTheDocument();

        // ⚠️ The empty branch renders a key the populated branch never touches,
        // so its translation is only provable here.
        expect(container.textContent).not.toContain('smart_qr.no_near_expire');
    });
});

describe('Admin/SmartQr/Dashboard — Locked Codes', () => {
    /** ⚠️ Must NOT become a 9th tile in the 8-card grid — it sits beside Quick actions. */
    it('renders the locked count outside the 8-tile stat grid', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        const tile = screen.getByText('Locked Codes').closest('.rounded-xl');
        expect(tile).not.toBeNull();
        expect(within(tile).getByText('3')).toBeInTheDocument();

        const statGrid = screen.getByText('Total QR Codes').closest('.grid');
        expect(within(statGrid).queryByText('Locked Codes'),
            'Locked Codes must not be inside the 8-tile grid').not.toBeInTheDocument();
    });

    it('renders a zero lock count rather than hiding the tile', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({ lockedCodes: 0 })} />);

        const tile = screen.getByText('Locked Codes').closest('.rounded-xl');
        expect(within(tile).getByText('0')).toBeInTheDocument();
    });
});

describe('Admin/SmartQr/Dashboard — Top Workspaces', () => {
    it('renders its current-state title and subtitle, not raw keys', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props()} />);

        // ⚠️ The title must read as CURRENT holdings — the whole point of
        // choosing this metric over lifetime "codes ever held".
        expect(screen.getByText('Codes Currently Held')).toBeInTheDocument();
        expect(screen.getByText('Current holdings by workspace')).toBeInTheDocument();
        expect(container.textContent).not.toMatch(/smart_qr\.top_workspaces/);
    });

    it('shows the empty state when no workspace holds a code', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props({ topWorkspaces: [] })} />);

        expect(screen.getByText('No codes are currently held.')).toBeInTheDocument();
        expect(container.textContent).not.toContain('smart_qr.no_top_workspaces');
    });

    it('hands the donut a name/value pair per workspace', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(donutPayload()).toEqual([
            { name: 'Acme Cafe', value: 6 },
            { name: 'Bloom Florist', value: 2 },
        ]);
    });

    /**
     * ⚠️ The backend FLAGS the bucket (name: null, is_other: true) rather than
     * naming it, because a literal 'Other' from the controller would be the one
     * string on this page no locale file could reach. The page supplies the
     * translated word — this asserts that handoff.
     */
    it('labels the flagged Other bucket from i18n, never from the payload', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            topWorkspaces: [
                { name: 'Acme Cafe', value: 9, is_other: false },
                { name: null, value: 4, is_other: true },
            ],
        })} />);

        expect(donutPayload()).toEqual([
            { name: 'Acme Cafe', value: 9 },
            { name: 'Other', value: 4 },
        ]);
    });

    /** A workspace deleted out from under its assignments must not render "null". */
    it('falls back to a dash for a missing workspace name', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            topWorkspaces: [{ name: null, value: 3, is_other: false }],
        })} />);

        expect(donutPayload()).toEqual([{ name: '—', value: 3 }]);
    });
});

describe('Admin/SmartQr/Dashboard — Scan Volume', () => {
    it('renders its title, the ending-yesterday note and both range buttons', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props()} />);

        expect(screen.getByText('Scan Volume')).toBeInTheDocument();
        expect(screen.getByText('Platform-wide, ending yesterday')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '7 days' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '30 days' })).toBeInTheDocument();
        expect(container.textContent).not.toMatch(/smart_qr\.(scan_volume|range_)/);
    });

    /** ⚠️ 30 is the default, and aria-pressed is what makes the state readable. */
    it('defaults to 30 days and switches to 7 on click', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        const sevenDay = screen.getByRole('button', { name: '7 days' });
        const thirtyDay = screen.getByRole('button', { name: '30 days' });

        expect(thirtyDay).toHaveAttribute('aria-pressed', 'true');
        expect(sevenDay).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(sevenDay);

        expect(sevenDay).toHaveAttribute('aria-pressed', 'true');
        expect(thirtyDay).toHaveAttribute('aria-pressed', 'false');
    });

    /**
     * ⚠️ An all-zero window shows the empty state rather than a flat line at
     * zero, which reads as "the chart is broken" instead of "nothing happened".
     */
    it('shows the empty state when every day in the window is zero', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props({
            scanVolume: SCAN_VOLUME.map((d) => ({ ...d, scans: 0, unique_scans: 0 })),
        })} />);

        expect(screen.getByText('No scans recorded in this period.')).toBeInTheDocument();
        expect(container.textContent).not.toContain('smart_qr.no_scan_data');
    });

    /**
     * ⚠️ The 7-day view slices the LAST 7 of the 30 already in hand. Seeded so
     * only the final two days are non-zero: switching to 7 days must keep them,
     * so an implementation slicing the FIRST 7 would show an empty chart here.
     */
    it('slices the LAST 7 days, not the first, when narrowed', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(linePayload()).toHaveLength(30);

        fireEvent.click(screen.getByRole('button', { name: '7 days' }));

        const seven = linePayload();
        expect(seven).toHaveLength(7);
        // The fixture puts scans only on the final two days, so slicing the
        // head would hand the chart seven zeroes.
        expect(seven.at(-1)).toEqual(SCAN_VOLUME.at(-1));
        expect(seven.some((d) => d.scans > 0)).toBe(true);
    });
});

describe('Admin/SmartQr/Dashboard — Recently Ended', () => {
    it('renders serial, workspace, ended date and the held duration', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        expect(screen.getByText('Recently Ended')).toBeInTheDocument();
        expect(screen.getByText('AX-000055')).toBeInTheDocument();
        expect(screen.getByText('Corner Deli')).toBeInTheDocument();
        expect(screen.getByText(formatDateTz(ENDED.unassigned_at, 'UTC'))).toBeInTheDocument();
        expect(screen.getByText('Held 7 days')).toBeInTheDocument();
    });

    /** ⚠️ 0 days must read as words, never as a bare "0" — that looks like a bug. */
    it('renders a sub-day period as "under a day", not zero', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({ recentlyEnded: [{ ...ENDED, held_days: 0 }] })} />);

        expect(screen.getByText('Held under a day')).toBeInTheDocument();
        expect(screen.queryByText('Held 0 days')).not.toBeInTheDocument();
    });

    /**
     * ⚠️ A repeated serial is CORRECT — one code, two tenancies. The workspace
     * column is what distinguishes them, so both must render.
     */
    it('renders a repeated serial across two tenancies without collapsing them', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            recentlyEnded: [
                { ...ENDED, uuid: 'e1', workspace_name: 'Corner Deli' },
                { ...ENDED, uuid: 'e2', workspace_name: 'Harbour Books' },
            ],
        })} />);

        expect(screen.getAllByText('AX-000055')).toHaveLength(2);
        expect(screen.getByText('Corner Deli')).toBeInTheDocument();
        expect(screen.getByText('Harbour Books')).toBeInTheDocument();
    });

    it('shows the translated empty state when nothing has ended', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props({ recentlyEnded: [] })} />);

        expect(screen.getByText('No assignment periods have ended yet.')).toBeInTheDocument();
        expect(container.textContent).not.toContain('smart_qr.no_recently_ended');
    });
});

describe('Admin/SmartQr/Dashboard — Recent Activity', () => {
    it('renders the raw action string, the actor and a formatted timestamp', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props()} />);

        // ⚠️ The action is shown VERBATIM by design — see the component's own
        // note. It is the one place a raw `smart_qr.*` string is correct.
        expect(screen.getByText('smart_qr.batch_created')).toBeInTheDocument();
        expect(screen.getByText('Super Admin')).toBeInTheDocument();
        expect(screen.getByText(formatDateTz(ACTIVITY.created_at, 'UTC'))).toBeInTheDocument();
    });

    /** An entry with no admin (a system/job-driven write) must not render blank. */
    it('falls back to "System" when an entry has no actor', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({ recentActivity: [{ ...ACTIVITY, actor_name: null }] })} />);

        expect(screen.getByText('System')).toBeInTheDocument();
    });

    /**
     * ⚠️ `smart_qr.no_recent_activity` was one of the 15 keys that shipped
     * un-invalidated and rendered raw. Asserting the ENGLISH here — not the
     * key — is what makes a missing key fail rather than pass silently.
     */
    it('shows the translated empty state when there is no activity', async () => {
        const Dashboard = await load();
        const { container } = render(<Dashboard {...props({ recentActivity: [] })} />);

        expect(screen.getByText('No recent activity.')).toBeInTheDocument();
        expect(container.textContent).not.toContain('smart_qr.no_recent_activity');
    });
});

describe('Admin/SmartQr/Dashboard — mount smoke test', () => {
    /**
     * ⚠️ The console assertion lives in the shared afterEach, not here — see the
     * note on that hook. This test's job is to exercise the multi-row shape the
     * other tests do not: several rows per panel, mixed statuses, so list keys
     * and per-row rendering are actually put under load.
     */
    it('mounts a full, realistic multi-row payload cleanly', async () => {
        const Dashboard = await load();
        render(<Dashboard {...props({
            recentBatches: [BATCH, { ...BATCH, uuid: 'b2', batch_number: 'AX-BK-002', status: 'printed' }],
            recentAssignments: [ASSIGNMENT, { ...ASSIGNMENT, uuid: 'a2', serial_number: 'AX-000043' }],
            recentActivity: [ACTIVITY, { ...ACTIVITY, id: 8, action: 'smart_qr.marked_printed' }],
        })} />);

        expect(screen.getByText('AX-BK-002')).toBeInTheDocument();
        expect(screen.getByText('AX-000043')).toBeInTheDocument();
        expect(screen.getByText('smart_qr.marked_printed')).toBeInTheDocument();
    });
});
