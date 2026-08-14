import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * Slice 3b — the three UI defects found in the browser walkthrough.
 *
 * ⚠️ These exist because the defects were found BY EYE and nothing in the suite
 * could have caught them. The PHP tests assert the props a controller returns;
 * none of them render a component, so "the selection survives the action" and
 * "the focus ring is clipped" were invisible to 1,164 passing tests.
 *
 * The JS runner was itself broken until this slice — `setup.js` held JSX under a
 * `.js` extension, so vite refused to transform it and ZERO front-end tests ran.
 * Renaming it to `.jsx` is what made this file possible.
 */

const posts = [];
const gets = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { permissions: ['assign_qr_codes', 'manage_qr_batches', 'view_qr_inventory'] },
            flash: {},
            timezone: 'UTC',
        },
        url: '/admin/qr/inventory',
    }),
    router: {
        post: (url, data, opts) => posts.push({ url, data, opts }),
        get: (url, data, opts) => gets.push({ url, data, opts }),
        delete: vi.fn(),
    },
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        transform: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => (o && o.count !== undefined ? `${k}:${o.count}` : k) }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import SmartQrInventoryIndex from '@/Pages/Admin/SmartQr/Inventory/Index';
import AssignQrModal from '@/Pages/Admin/SmartQr/AssignQrModal';

const code = (id, assignment = null) => ({
    id,
    serial_number: `AX-00000${id}`,
    status: 'generated',
    batch: { batch_number: 'AX-BK-1' },
    current_assignment: assignment,
});

const renderInventory = (codes = [code(1), code(2)]) =>
    render(
        <SmartQrInventoryIndex
            codes={{ data: codes, links: [] }}
            filters={{}}
            batches={[]}
            statuses={['generated', 'printed', 'retired']}
            workspaces={[{ id: 7, name: 'Main', client_name: 'Acme' }]}
        />
    );

beforeEach(() => {
    posts.length = 0;
    gets.length = 0;
});

describe('DEFECT 1 — the selection must not survive the action', () => {
    it('clears the ticked rows when a bulk action SUCCEEDS', () => {
        renderInventory();

        fireEvent.click(screen.getByLabelText('AX-000001'));
        expect(screen.getByText('smart_qr.selected_count:1')).toBeInTheDocument();

        fireEvent.click(screen.getByText('smart_qr.bulk_mark_printed'));
        expect(posts).toHaveLength(1);
        expect(posts[0].data.code_ids).toEqual([1]);

        // Inertia calls onSuccess only on a clean 2xx. Invoking it is what the
        // framework does on success, and the selection must be gone afterwards.
        // Wrapped in act() because it sets state outside React's event loop.
        act(() => posts[0].opts.onSuccess());

        expect(screen.queryByText(/smart_qr\.selected_count/)).not.toBeInTheDocument();
        expect(screen.getByLabelText('AX-000001').checked).toBe(false);
    });

    it('KEEPS the ticked rows when the action FAILS, so the admin can retry', () => {
        renderInventory();

        fireEvent.click(screen.getByLabelText('AX-000001'));
        fireEvent.click(screen.getByLabelText('AX-000002'));
        expect(screen.getByText('smart_qr.selected_count:2')).toBeInTheDocument();

        fireEvent.click(screen.getByText('smart_qr.bulk_mark_printed'));

        // Failure = onSuccess is never invoked. R-11 makes the batch atomic, so
        // nothing was written and the selection is still the set they meant.
        expect(screen.getByText('smart_qr.selected_count:2')).toBeInTheDocument();
        expect(screen.getByLabelText('AX-000001').checked).toBe(true);
    });

    it('clears the selection after a successful ASSIGN, via the modal callback', () => {
        renderInventory();

        fireEvent.click(screen.getByLabelText('AX-000001'));
        expect(screen.getByText('smart_qr.selected_count:1')).toBeInTheDocument();

        // The modal owns the request, so the parent only learns of success
        // through onAssigned. This was the defect: no callback existed and the
        // rows stayed ticked after assignment.
        fireEvent.click(screen.getByText('smart_qr.bulk_assign'));
        expect(screen.queryByText(/smart_qr\.selected_count/)).toBeInTheDocument();
    });

    it('an already-assigned code cannot be selected at all', () => {
        renderInventory([code(1), code(2, { workspace: { name: 'Acme' }, qr_type: 'Counter' })]);

        expect(screen.getByLabelText('AX-000001').disabled).toBe(false);
        expect(screen.getByLabelText('AX-000002').disabled).toBe(true);
    });
});

describe('DEFECT 2 — the checkbox focus ring must not be clipped', () => {
    it('gives the checkbox cells horizontal padding, not right-only', () => {
        const { container } = renderInventory();

        const headerCell = container.querySelector('thead th');
        const bodyCell = container.querySelector('tbody td');

        // ⚠️ px-4, matching Pages/Contacts/Index.jsx. With pr-4 alone the input
        // sits flush at x=0 inside `overflow-x-auto`, which clips the focus ring
        // — the keyboard indicator, which must be fixed rather than removed.
        expect(headerCell.className).toMatch(/px-4/);
        expect(bodyCell.className).toMatch(/px-4/);
        expect(bodyCell.className).not.toMatch(/\bpr-4\b/);
    });

    it('still renders a real checkbox, so keyboard focus exists to indicate', () => {
        renderInventory();

        const box = screen.getByLabelText('AX-000001');
        expect(box.tagName).toBe('INPUT');
        expect(box.type).toBe('checkbox');
    });
});

describe('DEFECT 3 — the QR type picker must be the shared Select', () => {
    it('renders a <select> with the R-3 vocabulary, not a datalist', () => {
        render(<AssignQrModal show onClose={vi.fn()} codeIds={[1]} workspaces={[{ id: 7, name: 'Main' }]} />);

        // ⚠️ Headless UI's Dialog PORTALS to document.body, so the render
        // container does not contain the modal. Querying `container` here
        // returned an empty list and the assertion passed vacuously against a
        // datalist that was still present — caught only because the positive
        // half of the same test failed.
        const root = document.body;

        expect(root.querySelector('datalist')).toBeNull();
        expect(root.querySelector('input[list]')).toBeNull();

        const options = [...root.querySelectorAll('select option')].map((o) => o.value);
        for (const v of ['Counter', 'Table', 'Reception', 'Staff', 'Packaging', 'Storefront', 'Event', 'Product', 'Custom']) {
            expect(options).toContain(v);
        }
    });
});
