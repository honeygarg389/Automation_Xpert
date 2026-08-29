import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS — the per-QR detail page.
 *
 *   the conditional block  name / QR type / message / destination phone render
 *                          ONLY when the code is assigned, because they live on
 *                          the assignment and have nowhere to be stored on an
 *                          unassigned code
 *   the hint               replaces them, rather than showing empty fields that
 *                          imply inventory-level storage that does not exist
 *   the batch link         uses the batch's UUID — the batch route key — not its
 *                          id, which would 404 at route binding
 *   the export links       are real anchors carrying ?format=, one per format
 *
 * ⚠️ The assignment assertions read RENDERED TEXT. A component that received the
 * props correctly and rendered nothing would pass any prop-shape check.
 */

const posts = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { permissions: ['view_qr_inventory', 'manage_qr_batches', 'assign_qr_codes'] },
            flash: {}, errors: {}, timezone: 'UTC',
        },
        url: '/admin/qr/inventory/AX-000001',
    }),
    router: { post: (url, data, opts) => posts.push({ url, data, opts }), reload: vi.fn(), get: vi.fn(), delete: vi.fn() },
    useForm: (initial) => ({ data: initial, setData: vi.fn(), post: vi.fn(), patch: vi.fn(), processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn(), transform: vi.fn() }),
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

import SmartQrCodeShow from '@/Pages/Admin/SmartQr/Inventory/Show';

const code = {
    id: 42, serial_number: 'AX-000001', status: 'generated', printed_at: null,
    created_at: '2026-01-01T00:00:00Z', public_url: 'https://app.test/q/abc123',
};

const batch = { uuid: 'batch-uuid-9', batch_number: 'AX-BK-1', batch_name: 'Kit' };

const assignment = {
    uuid: 'a-uuid', name: 'Front counter', qr_type: 'table-tent',
    default_message: 'Hi from table 4', status: 'active',
    starts_at: '2026-01-02T00:00:00Z', expires_at: null,
    workspace_name: 'Acme Cafe', assigned_user_name: 'Sam',
    destination_phone: 'Front Desk Line',
};

const renderPage = (over = {}) =>
    render(
        <SmartQrCodeShow
            code={code}
            batch={batch}
            currentAssignment={null}
            statuses={['generated', 'printed', 'damaged', 'lost', 'retired']}
            exportFormats={['svg', 'png', 'pdf']}
            workspaces={[{ id: 1, name: 'Acme Cafe' }]}
            {...over}
        />,
    );

beforeEach(() => { posts.length = 0; });

describe('header', () => {
    it('shows the serial in the title', () => {
        renderPage();
        expect(screen.getByText('QR: AX-000001')).toBeTruthy();
    });

    it('shows the assignment name under the serial only when assigned', () => {
        renderPage();
        expect(screen.queryByText('Front counter')).toBeNull();

        renderPage({ currentAssignment: assignment });
        expect(screen.queryAllByText('Front counter').length).toBeGreaterThan(0);
    });
});

describe('overview panel', () => {
    it('always renders the public URL', () => {
        renderPage();
        expect(screen.getByText('https://app.test/q/abc123')).toBeTruthy();
    });

    /**
     * ⚠️ THE BATCH ROUTE KEY IS uuid. Passing batch.id 404s at route binding
     * before the controller runs, so the link would look fine and be dead.
     */
    it('links the batch by uuid, not id', () => {
        renderPage();
        const link = screen.getByText('AX-BK-1').closest('a');
        expect(link.getAttribute('href')).toContain('batch-uuid-9');
        expect(link.getAttribute('href')).toContain('admin.qr.batches.show');
    });

    it('shows the not-printed state for a code with no printed_at', () => {
        renderPage();
        expect(screen.getByText('Not printed')).toBeTruthy();
    });
});

describe('assignment block — unassigned', () => {
    /**
     * ⚠️ THE CENTRAL SHAPE ASSERTION. These four fields live on the assignment;
     * an unassigned code has nowhere to store them, so rendering empty inputs
     * would promise storage that does not exist.
     */
    it.each([
        ['Destination phone'],
        ['Message override'],
        ['Start date'],
        ['Expiry date'],
    ])('does not render the %s field', (label) => {
        renderPage();
        expect(screen.queryByText(label)).toBeNull();
    });

    it('shows the hint instead', () => {
        renderPage();
        expect(screen.getByText(/Assign this QR to add a name, type, or message/i)).toBeTruthy();
    });
});

describe('assignment block — assigned', () => {
    it('renders every assignment-scoped field from currentAssignment', () => {
        renderPage({ currentAssignment: assignment });

        expect(screen.getByText('Front Desk Line')).toBeTruthy();
        expect(screen.getByText('Acme Cafe')).toBeTruthy();
        expect(screen.getByText('table-tent')).toBeTruthy();
        expect(screen.getByText('Hi from table 4')).toBeTruthy();
        expect(screen.getByText('Sam')).toBeTruthy();
    });

    it('drops the hint once assigned', () => {
        renderPage({ currentAssignment: assignment });
        expect(screen.queryByText(/Assign this QR to add a name/i)).toBeNull();
    });
});

describe('export dropdown', () => {
    /**
     * ⚠️ REAL ANCHORS. Dropdown.Item defaults to <button>, so an href on it
     * renders a button that does nothing; an Inertia Link would intercept the
     * navigation and wait for an Inertia response a file download never sends.
     */
    it('offers one download anchor per format, each carrying its format param', () => {
        renderPage();
        // ⚠️ Dropdown.Content mounts only while open — asserting before the
        // click finds nothing and passes any "not present" check by accident.
        fireEvent.click(screen.getByText('Export QR'));

        for (const fmt of ['svg', 'png', 'pdf']) {
            const anchors = screen.getAllByRole('link')
                .filter((a) => (a.getAttribute('href') || '').includes('inventory.download'));
            expect(anchors.some((a) => a.getAttribute('href').includes(fmt))).toBe(true);
        }
    });

    it('points at the admin download route, not the client one', () => {
        renderPage();
        fireEvent.click(screen.getByText('Export QR'));
        const anchors = screen.getAllByRole('link').map((a) => a.getAttribute('href') || '');
        const dl = anchors.filter((h) => h.includes('download'));
        expect(dl.length).toBeGreaterThan(0);
        expect(dl.every((h) => h.includes('admin.qr.inventory.download'))).toBe(true);
    });
});

describe('change stage', () => {
    /**
     * ⚠️ Posts to the EXISTING bulk endpoint with a one-element array — no new
     * route. code_ids carries the id, not the serial, because the endpoint
     * validates exists:smart_qr_codes,id.
     */
    it('posts code_ids as a one-element array of the id', () => {
        renderPage();
        fireEvent.click(screen.getByText('Change stage'));
        fireEvent.click(screen.getByText('Printed'));

        expect(posts.length).toBe(1);
        expect(posts[0].url).toContain('admin.qr.inventory.change-status');
        expect(posts[0].data.code_ids).toEqual([42]);
        expect(posts[0].data.status).toBe('printed');
    });
});

describe('preview', () => {
    it('uses the ADMIN preview route, not the workspace-scoped client one', () => {
        renderPage();
        const img = screen.getByAltText('AX-000001');
        expect(img.getAttribute('src')).toContain('admin.qr.inventory.preview');
        expect(img.getAttribute('src')).not.toContain('client');
    });

    it('captions the preview so an admin knows it is testable', () => {
        renderPage();
        expect(screen.getByText(/Scan this with a phone/i)).toBeTruthy();
    });
});
