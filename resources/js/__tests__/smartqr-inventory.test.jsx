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
const formPosts = [];

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
        // ⚠️ Recorded, not a bare vi.fn(). The assign path posts through
        // useForm inside the MODAL, so the only way to simulate a successful
        // assignment is to invoke the onSuccess it registered.
        post: (url, opts) => formPosts.push({ url, opts }),
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
    formPosts.length = 0;
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

    /**
     * ⚠️ THE DISCRIMINATOR FOR THIS DEFECT, and the first version was vacuous.
     *
     * `bulk()` ALREADY cleared the selection before this slice — mark-printed
     * and change-status were never broken. The defect was ASSIGN alone, because
     * the modal owns that request and the selection lives in the parent.
     *
     * Measured: the first version of this test opened the modal and asserted the
     * selection was still there, which is true both before and after the fix. It
     * PASSED against the pre-fix code. Running the new tests against commit
     * 2dc1488 is what exposed it — defects 2 and 3 failed there, defect 1 did not.
     *
     * So the assertion has to reach through the modal: capture the onSuccess the
     * modal registered, invoke it, and check the PARENT's selection cleared.
     */
    it('clears the selection after a successful ASSIGN, through the modal callback', () => {
        renderInventory();

        fireEvent.click(screen.getByLabelText('AX-000001'));
        fireEvent.click(screen.getByText('smart_qr.bulk_assign'));

        // ⚠️ The modal's form, not the page's FILTER form. Both live under
        // document.body once the Dialog portals, and querySelector('form')
        // returns the filter one — which submits nothing and left formPosts
        // empty. Scoped to the dialog instead.
        const dialog = document.body.querySelector('[role="dialog"]');
        fireEvent.submit(dialog.querySelector('form'));

        expect(formPosts).toHaveLength(1);

        // What Inertia does on a 2xx. Pre-fix there was no onAssigned callback,
        // so this cleared nothing and the rows stayed ticked.
        act(() => formPosts[0].opts.onSuccess());

        expect(screen.queryByText(/smart_qr\.selected_count/)).not.toBeInTheDocument();
        expect(screen.getByLabelText('AX-000001').checked).toBe(false);
    });

    it('KEEPS the selection when an ASSIGN is refused', () => {
        renderInventory();

        fireEvent.click(screen.getByLabelText('AX-000001'));
        fireEvent.click(screen.getByText('smart_qr.bulk_assign'));
        fireEvent.submit(document.body.querySelector('[role="dialog"]').querySelector('form'));

        // onSuccess never fires on a refusal. R-11 guarantees nothing was
        // written, so the admin's selection is still the set they meant.
        expect(screen.getByText('smart_qr.selected_count:1')).toBeInTheDocument();
        expect(screen.getByLabelText('AX-000001').checked).toBe(true);
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

describe('assign modal — static submit label', () => {
    /**
     * ⚠️ ASSERTED IN THIS FILE'S KEY CONVENTION, and it still discriminates.
     * The t() mock here returns the raw key, and appends ":<count>" when a count
     * option is passed — so the OLD code rendered "smart_qr.assign_confirm:5" and
     * the new one renders "smart_qr.assign_qr". The absence of the count suffix
     * is the whole point of the change, and this harness shows it directly.
     *
     * ⚠️ The rendered VALUE ("Assign QR") cannot be checked here — that needs a
     * mock reading en.json, which smartqr-inventory-detail.test.jsx does. Both
     * halves are covered, in the file able to see each.
     *
     * Two counts, because a single-count assertion passes against a string that
     * still interpolates.
     */
    it.each([[[1]], [[1, 2, 3, 4, 5]]])('is the same key at any count: %j', (ids) => {
        render(<AssignQrModal show onClose={vi.fn()} codeIds={ids} workspaces={[{ id: 7, name: 'Main' }]} />);

        const submit = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.getAttribute('type') === 'submit');

        expect(submit).toBeDefined();
        expect(submit.textContent.trim()).toBe('smart_qr.assign_qr');
        expect(submit.textContent).not.toMatch(/\d/);
        expect(submit.textContent).not.toContain('assign_confirm');
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
