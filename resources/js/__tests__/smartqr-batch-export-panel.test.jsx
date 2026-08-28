import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS.
 *
 *   the button   dispatches to the BATCH route with the batch's UUID, and is
 *                no longer the disabled "coming soon" placeholder
 *   the panel    renders one row per part with its status, and offers a
 *                download link for READY parts ONLY
 *   the guard    a second click cannot queue a duplicate set of N parts
 *
 * ⚠️ The download-link assertions are the ones that matter: a link rendered for
 * a queued or failed part points at a 404, and the admin discovers it by
 * clicking. The server refuses those too — this is the half that stops them
 * being offered in the first place.
 */

const posts = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { permissions: ['manage_qr_batches', 'view_qr_inventory'] }, flash: {}, errors: {}, timezone: 'UTC' },
        url: '/admin/qr/batches/x',
    }),
    router: { post: (url, data, opts) => posts.push({ url, data, opts }), get: vi.fn(), delete: vi.fn() },
    useForm: (initial) => ({ data: initial, setData: vi.fn(), post: vi.fn(), patch: vi.fn(), processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn() }),
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

import SmartQrBatchShow from '@/Pages/Admin/SmartQr/Batches/Show';

const batch = {
    id: 1, uuid: 'batch-uuid-1', batch_name: 'B', batch_number: 'AX-BK-1', prefix: 'AX',
    quantity: 1500, serial_start: 1, status: 'generated', generated_count: 1500,
    printed_count: 0, assigned_count: 0, active_count: 0, created_at: '2026-01-01',
    failure_reason: null,
};

const renderPage = (exports = []) =>
    render(<SmartQrBatchShow batch={batch} codes={{ data: [], links: [], current_page: 1, last_page: 1, total: 0 }} exports={exports} />);

beforeEach(() => { posts.length = 0; });

describe('Export ZIP dropdown', () => {
    // The trigger is the visible button; the three format items live in the
    // Dropdown.Content that opens on click.
    const openMenu = () => {
        fireEvent.click(screen.getByRole('button', { name: /Export ZIP/ }));
    };

    it('is enabled and no longer the coming-soon placeholder', () => {
        renderPage();
        const btn = screen.getByRole('button', { name: /Export ZIP/ });
        expect(btn).toBeInTheDocument();
        expect(btn).not.toBeDisabled();
        expect(screen.queryByTitle('Coming soon')).not.toBeInTheDocument();
    });

    it('offers exactly the three formats the backend accepts', () => {
        // ⚠️ Must match GenerateQrExportJob::FORMATS. An option the server
        // rejects would fail validation after the click, and a missing one is
        // a format the admin cannot reach at all.
        renderPage();
        openMenu();

        expect(screen.getByRole('button', { name: 'SVG' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'PNG' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'PDF (print)' })).toBeInTheDocument();
    });

    it.each([
        ['SVG', 'svg'],
        ['PNG', 'png'],
        ['PDF (print)', 'pdf'],
    ])('dispatches %s with format=%s to the BATCH route', (label, fmt) => {
        renderPage();
        openMenu();
        fireEvent.click(screen.getByRole('button', { name: label }));

        expect(posts).toHaveLength(1);
        expect(posts[0].data).toEqual({ format: fmt });
        // ⚠️ The UUID, not the id — batches use uuid as their route key, so
        // posting batch.id would 404 at route-model binding.
        expect(posts[0].url).toContain('batch-uuid-1');
        expect(posts[0].url).toContain('admin.qr.batches.export');
        expect(posts[0].opts.preserveScroll).toBe(true);
    });

    it('does not target the Inventory export route', () => {
        // ⚠️ The old flow is a separate path and must stay untouched. Posting
        // there would send no code_ids and silently export nothing.
        renderPage();
        openMenu();
        fireEvent.click(screen.getByRole('button', { name: 'SVG' }));
        expect(posts[0].url).not.toContain('inventory');
    });

    it.each(['SVG', 'PNG', 'PDF (print)'])(
        'disables the trigger after choosing %s, so a second dispatch is impossible',
        (label) => {
            // ⚠️ ASSERTED FOR EVERY OPTION, not just the default. The guard sits
            // on the trigger and each item sets `exporting` — a version that
            // only set it on one path would still double-dispatch from the
            // other two. A 10,000-code batch queues 20 jobs per request, so a
            // double-click is 40 jobs and 40 tracking rows.
            renderPage();
            fireEvent.click(screen.getByRole('button', { name: /Export ZIP/ }));
            fireEvent.click(screen.getByRole('button', { name: label }));

            expect(posts).toHaveLength(1);
            expect(screen.getByRole('button', { name: /Export ZIP/ })).toBeDisabled();
        },
    );
});

describe('Export parts panel', () => {
    const part = (over = {}) => ({
        id: 10, part_number: 1, total_parts: 3, format: 'svg',
        status: 'ready', path: 'smartqr-exports/a.zip', error: null, ...over,
    });

    it('is absent entirely when the batch has never been exported', () => {
        renderPage([]);
        expect(screen.queryByText('Export parts')).not.toBeInTheDocument();
    });

    it('renders one row per part with its part number and status', () => {
        renderPage([
            part({ id: 10, part_number: 1, status: 'ready' }),
            part({ id: 11, part_number: 2, status: 'processing', path: null }),
            part({ id: 12, part_number: 3, status: 'queued', path: null }),
        ]);

        expect(screen.getByText('Export parts')).toBeInTheDocument();
        expect(screen.getByText('Part 1 of 3')).toBeInTheDocument();
        expect(screen.getByText('Part 2 of 3')).toBeInTheDocument();
        expect(screen.getByText('Part 3 of 3')).toBeInTheDocument();

        expect(screen.getByText('Ready')).toBeInTheDocument();
        expect(screen.getByText('Building')).toBeInTheDocument();
        expect(screen.getByText('Queued')).toBeInTheDocument();
    });

    it('offers a download link for a READY part only', () => {
        renderPage([
            part({ id: 10, part_number: 1, status: 'ready' }),
            part({ id: 11, part_number: 2, status: 'queued', path: null }),
            part({ id: 12, part_number: 3, status: 'processing', path: null }),
            part({ id: 13, part_number: 4, status: 'failed', path: null, error: 'boom' }),
        ]);

        // ⚠️ Exactly one — a link on a not-ready part points at a 404 the admin
        // finds by clicking.
        const links = screen.getAllByRole('link', { name: /Download/ });
        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toContain('batch-uuid-1');
        expect(links[0].getAttribute('href')).toContain('10');
    });

    it('shows the failure reason on a failed part', () => {
        // The reason exists on the row precisely so it reaches the admin, who
        // never sees the log line.
        renderPage([part({ status: 'failed', path: null, error: 'render exploded' })]);
        expect(screen.getByText(/render exploded/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Download/ })).not.toBeInTheDocument();
    });
});

/**
 * ⚠️ SECTION ORDER, ASSERTED ON DOM POSITION.
 *
 * The codes table is the reason an admin opens this page; the export parts
 * panel is a side channel that only exists after someone clicks Export. The
 * panel originally rendered between the stat pills and the table, pushing the
 * table below a list that is empty on most batches.
 *
 * compareDocumentPosition is used rather than reading class names or indexes:
 * it asserts the actual rendered order, which is the thing that regressed, and
 * it survives markup changes that do not move the sections relative to each
 * other.
 */
describe('Batch detail — section order', () => {
    it('renders the codes table BEFORE the export parts panel', () => {
        renderPage([{
            id: 10, part_number: 1, total_parts: 1, format: 'svg',
            status: 'ready', path: 'smartqr-exports/a.zip', error: null,
        }]);

        const table = document.querySelector('table');
        const panelHeading = screen.getByText('Export parts');

        expect(table).not.toBeNull();
        expect(panelHeading).toBeInTheDocument();

        // Node.DOCUMENT_POSITION_FOLLOWING (4) => panel comes AFTER the table.
        const position = table.compareDocumentPosition(panelHeading);
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('keeps the stat pills above the codes table', () => {
        // Positive control: proves the assertion above is reading real order
        // rather than passing on any two nodes.
        renderPage();

        const pills = screen.getByText('Ordered');
        const table = document.querySelector('table');

        expect(pills.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});
