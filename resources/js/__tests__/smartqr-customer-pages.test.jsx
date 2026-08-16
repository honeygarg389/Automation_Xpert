import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, within, fireEvent } from '@testing-library/react';

/**
 * ⚠️ CLOSES THE OWED ENTRY — the half that has been open since slice 7b.
 *
 * 7b closed the R-19 LABEL half (`smartqr-labels.test.jsx`). The entry named
 * three customer pages; Overview was covered there, and `Codes.jsx` and
 * `Activity.jsx` were left. This file covers both.
 *
 * What is here is deliberately only what PHP cannot see. The boundary and
 * entitlement questions — whose codes a customer reaches, whether a reassigned
 * code leaves the previous tenant, whether the gate refuses — are all covered by
 * stash-checked PHP tests, and a React test could not catch any of them.
 *
 * These four things it CAN catch:
 *
 *   1. The preview `<img>` points at the preview ENDPOINT. Slice 8b wired it,
 *      and the failure mode is silent: a wrong `src` renders a broken-image icon
 *      in a table cell nobody looks at twice, and PHP sees a passing route test
 *      for an endpoint the page never calls.
 *   2. The download menu offers all three formats and puts the chosen one in the
 *      query string. `?format=` is a string contract between JSX and a `match`
 *      in the controller, and nothing else asserts the two agree.
 *   3. The `canManage` split — the edit control absent for staff.
 *   4. Activity's badge mapping, which is the ONE place a bot scan is SHOWN
 *      rather than excluded. Everywhere else in the module bots are filtered out
 *      of the numbers, so a mapping that quietly labelled a bot "Unique" would
 *      contradict every other screen.
 */

const visits = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: {}, flash: {}, timezone: 'UTC' }, url: '/' }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
    useForm: () => ({ data: {}, setData: vi.fn(), patch: vi.fn(), processing: false, errors: {} }),
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

const EN = {
    'smart_qr.col_preview': 'Preview',
    'smart_qr.col_serial': 'Serial',
    'smart_qr.download_label': 'Download',
    'smart_qr.format_svg': 'SVG',
    'smart_qr.format_png': 'PNG',
    'smart_qr.format_pdf': 'PDF (print)',
    'smart_qr.edit_title': 'Edit QR code',
    'smart_qr.no_codes_yet': 'No QR codes yet',
    'smart_qr.no_activity': 'No activity yet',
    'smart_qr.scan_bot': 'Bot',
    'smart_qr.scan_unique': 'Unique',
    'smart_qr.scan_repeat': 'Repeat',
};

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        // Falls back to the key so a MISSING key shows up as a failed assertion
        // rather than as an empty string that quietly matches nothing.
        t: (k, opts) => EN[k] ?? (typeof opts === 'string' ? opts : k),
    }),
}));

/**
 * ⚠️ Ziggy's `route()` is a global in this app, not an import. Mocking it to
 * return a RECOGNISABLE string means an assertion on the `src` proves the page
 * called the named route — not merely that some URL was produced.
 */
beforeEach(() => {
    visits.length = 0;
    global.route = (name, param) => `/__route__/${name}/${param ?? ''}`;
    delete window.location;
    window.location = {
        get href() { return ''; },
        set href(v) { visits.push(v); },
    };
});

afterEach(() => { vi.clearAllMocks(); });

const page = (codes) => ({
    codes: { data: codes, links: [], current_page: 1, last_page: 1, total: codes.length },
    stats: {},
    channels: [],
    users: [],
});

const code = (over = {}) => ({
    id: 1,
    serial_number: 'AX-0001',
    status: 'printed',
    current_assignment: { name: 'Front counter', qr_type: 'Counter', status: 'active' },
    ...over,
});

describe('client/SmartQr/Codes.jsx', () => {
    it('renders the preview from the preview endpoint, not from a stored file', async () => {
        const { default: Codes } = await import('@/Pages/client/SmartQr/Codes');

        render(<Codes {...page([code()])} canManage={false} />);

        const img = document.querySelector('img');

        expect(img).not.toBeNull();

        // ⚠️ The route NAME and the serial both, because the endpoint is keyed
        // by serial — passing `id` would 404 into a broken-image icon, and this
        // codebase has a documented history of exactly that mistake with route
        // keys (see CLAUDE.md).
        expect(img.getAttribute('src')).toBe('/__route__/client.smartqr.codes.preview/AX-0001');
    });

    it('offers all three download formats and sends the chosen one as ?format=', async () => {
        const { default: Codes } = await import('@/Pages/client/SmartQr/Codes');

        render(<Codes {...page([code()])} canManage={false} />);

        const menu = screen.getByLabelText('Download');

        expect(
            [...menu.querySelectorAll('option')].map((o) => o.value).filter(Boolean)
        ).toEqual(['svg', 'png', 'pdf']);

        fireEvent.change(menu, { target: { value: 'png' } });

        // The contract with the controller's `match ($format)` — a rename on
        // either side breaks here and nowhere else.
        expect(visits).toEqual(['/__route__/client.smartqr.codes.download/AX-0001?format=png']);
    });

    it('does not navigate when the placeholder is re-selected', async () => {
        const { default: Codes } = await import('@/Pages/client/SmartQr/Codes');

        render(<Codes {...page([code()])} canManage={false} />);

        fireEvent.change(screen.getByLabelText('Download'), { target: { value: '' } });

        expect(visits).toEqual([]);
    });

    it('hides the edit control from staff and shows it to administrators', async () => {
        const { default: Codes } = await import('@/Pages/client/SmartQr/Codes');

        const { unmount } = render(<Codes {...page([code()])} canManage={false} />);
        expect(screen.queryByLabelText('Edit QR code')).toBeNull();
        unmount();

        // POSITIVE CONTROL. Without it, a page that rendered no rows at all
        // would pass the negative assertion above.
        render(<Codes {...page([code()])} canManage />);
        expect(screen.getByLabelText('Edit QR code')).toBeTruthy();
    });

    it('shows the empty state and no preview requests when there are no codes', async () => {
        const { default: Codes } = await import('@/Pages/client/SmartQr/Codes');

        render(<Codes {...page([])} canManage />);

        expect(screen.getByText('No QR codes yet')).toBeTruthy();

        // An <img> here would be a request for artwork of a code that does not
        // exist — a 404 per empty render.
        expect(document.querySelector('img')).toBeNull();
    });
});

describe('client/SmartQr/Activity.jsx', () => {
    const scans = (rows) => ({
        scans: { data: rows, links: [], current_page: 1, last_page: 1, total: rows.length },
    });

    const scan = (id, over = {}) => ({
        id,
        serial_number: `AX-000${id}`,
        scanned_at: '2026-08-14T10:00:00Z',
        is_bot: false,
        is_unique: false,
        ...over,
    });

    /**
     * ⚠️ THE ONE PLACE BOTS ARE SHOWN.
     *
     * Slice 4 ruled bots are FLAGGED, not dropped, and every aggregate excludes
     * them. This feed displays them, so the mapping has to be exact: a bot that
     * rendered as "Unique" would be counted by a human reading the feed and not
     * by any number on any other screen.
     *
     * All three rows in one render, so a mapping collapsed to a single branch
     * cannot pass — it would have to produce three different labels by accident.
     */
    it('labels bot, unique and repeat scans distinctly', async () => {
        const { default: Activity } = await import('@/Pages/client/SmartQr/Activity');

        render(<Activity {...scans([
            scan(1, { is_bot: true, is_unique: true }),
            scan(2, { is_unique: true }),
            scan(3),
        ])} />);

        const rows = document.querySelectorAll('tbody tr');

        expect(rows).toHaveLength(3);

        // ⚠️ Row 1 is `is_bot AND is_unique`, and it must read "Bot". Bot is
        // checked first for a reason: a bot's first visit IS unique by the
        // fingerprint, so the two flags co-occur constantly and the precedence
        // is the whole substance of the mapping.
        expect(within(rows[0]).getByText('Bot')).toBeTruthy();
        expect(within(rows[0]).queryByText('Unique')).toBeNull();

        expect(within(rows[1]).getByText('Unique')).toBeTruthy();
        expect(within(rows[2]).getByText('Repeat')).toBeTruthy();
    });

    it('shows the empty state when there is no activity', async () => {
        const { default: Activity } = await import('@/Pages/client/SmartQr/Activity');

        render(<Activity {...scans([])} />);

        expect(screen.getByText('No activity yet')).toBeTruthy();
    });
});
