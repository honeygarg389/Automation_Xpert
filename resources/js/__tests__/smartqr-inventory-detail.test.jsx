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
const deletes = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { permissions: ['view_qr_inventory', 'manage_qr_batches', 'assign_qr_codes'] },
            flash: {}, errors: {}, timezone: 'UTC',
        },
        url: '/admin/qr/inventory/AX-000001',
    }),
    router: {
        post: (url, data, opts) => posts.push({ url, data, opts }),
        delete: (url, opts) => deletes.push({ url, opts }),
        reload: vi.fn(), get: vi.fn(),
    },
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

/**
 * The VALUE cell of one Overview row, found by its label.
 *
 * ⚠️ Needed because "Printed" legitimately appears twice once the fix lands —
 * once in the status badge and once in this field. That agreement is the point,
 * so a bare getByText('Printed') matches both and throws. Scoping to the row
 * asserts the field itself rather than whichever element happens to be first.
 */
const fieldValue = (label) => {
    const labelEl = Array.from(document.querySelectorAll('span'))
        .find((el) => el.textContent === label);
    return labelEl?.nextElementSibling?.textContent ?? null;
};

const renderPage = ({ code: codeOver, ...over } = {}) =>
    render(
        <SmartQrCodeShow
            code={codeOver ?? code}
            batch={batch}
            currentAssignment={null}
            statuses={['generated', 'printed', 'damaged', 'lost', 'retired']}
            exportFormats={['svg', 'png', 'pdf']}
            workspaces={[{ id: 1, name: 'Acme Cafe' }]}
            {...over}
        />,
    );

beforeEach(() => {
    posts.length = 0;
    deletes.length = 0;
    window.confirm = vi.fn(() => true);
});

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

describe('printed status — cannot contradict the badge', () => {
    /**
     * ⚠️ THE REPORTED SYMPTOM. A code whose status is `printed` but whose
     * printed_at was never written (Change Stage before the fix, or any historic
     * row) must not read "Not printed" beside a badge saying "Printed".
     */
    it('reads Printed for status=printed even with no timestamp', () => {
        renderPage({ code: { ...code, status: 'printed', printed_at: null } });
        expect(fieldValue('Printed status')).toBe('Printed');
    });

    /** The other direction: printed then damaged keeps reading as printed. */
    it('reads Printed when a timestamp exists but the status has moved on', () => {
        renderPage({ code: { ...code, status: 'damaged', printed_at: '2026-03-12T00:00:00Z' } });
        expect(fieldValue('Printed status')).toContain('Printed');
        expect(fieldValue('Printed status')).not.toBe('Not printed');
    });

    it('reads Not printed only when neither signal is set', () => {
        renderPage({ code: { ...code, status: 'generated', printed_at: null } });
        expect(fieldValue('Printed status')).toBe('Not printed');
    });

    /**
     * ⚠️ No invented date. A code known printed without a timestamp says so
     * without fabricating when.
     */
    it('omits the date when the code is printed but has no timestamp', () => {
        renderPage({ code: { ...code, status: 'printed', printed_at: null } });
        expect(fieldValue('Printed status')).toBe('Printed');
        expect(fieldValue('Printed status')).not.toContain('·');
    });
});

describe('active status row', () => {
    /**
     * ⚠️ POSITION IS THE REQUIREMENT: Batch → Active status → Printed status.
     * Asserted by DOM order, since "the row exists" would pass wherever it sat.
     */
    it('sits between Batch and Printed status when assigned', () => {
        renderPage({ currentAssignment: assignment });

        const labels = Array.from(document.querySelectorAll('span'))
            .map((el) => el.textContent)
            .filter((t) => ['Batch', 'Active status', 'Printed status'].includes(t));

        expect(labels).toEqual(['Batch', 'Active status', 'Printed status']);
    });

    it('is absent entirely when the code is unassigned', () => {
        renderPage();
        expect(screen.queryByText('Active status')).toBeNull();
    });

    /** ⚠️ The ASSIGNMENT's vocabulary (active/inactive/ended), not the code's. */
    it('renders the assignment status, not the code status', () => {
        renderPage({ currentAssignment: { ...assignment, status: 'inactive' } });
        expect(screen.getByText('Inactive')).toBeTruthy();
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

describe('download buttons', () => {
    /**
     * ⚠️ THEY MOVED OUT OF THE HEADER. The dropdown put the format choice three
     * panels away from the image it produces; these now sit under it. Asserted by
     * DOM POSITION, not merely by existence — a test that only checked "an anchor
     * exists" would pass with them back in the header.
     */
    it('renders the download anchors after the preview image in DOM order', () => {
        renderPage();

        const img = screen.getByAltText('AX-000001');
        const anchors = screen.getAllByRole('link')
            .filter((a) => (a.getAttribute('href') || '').includes('inventory.download'));

        expect(anchors.length).toBe(3);
        for (const a of anchors) {
            const after = img.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING;
            expect(after).toBeTruthy();
        }
    });

    it('offers exactly one anchor per format, each carrying its format param', () => {
        renderPage();
        const hrefs = screen.getAllByRole('link')
            .map((a) => a.getAttribute('href') || '')
            .filter((h) => h.includes('inventory.download'));

        for (const fmt of ['svg', 'png', 'pdf']) {
            expect(hrefs.filter((h) => h.includes(fmt)).length).toBe(1);
        }
    });

    /**
     * ⚠️ NO DROPDOWN ANY MORE. Pins the removal, so re-adding one would fail
     * rather than silently leaving two ways to do the same thing.
     */
    it('no longer offers an Export QR dropdown in the header', () => {
        renderPage();
        expect(screen.queryByText('Export QR')).toBeNull();
    });

    it('points at the admin download route, not the client one', () => {
        renderPage();
        const dl = screen.getAllByRole('link')
            .map((a) => a.getAttribute('href') || '')
            .filter((h) => h.includes('download'));
        expect(dl.length).toBeGreaterThan(0);
        expect(dl.every((h) => h.includes('admin.qr.inventory.download'))).toBe(true);
    });
});

describe('assign / unassign — mutually exclusive', () => {
    it('shows Assign QR and not Unassign QR when unassigned', () => {
        renderPage();
        expect(screen.queryByText('Assign QR')).not.toBeNull();
        expect(screen.queryByText('Unassign QR')).toBeNull();
    });

    it('shows Unassign QR and not Assign QR when assigned', () => {
        renderPage({ currentAssignment: assignment });
        expect(screen.queryByText('Unassign QR')).not.toBeNull();
        expect(screen.queryByText('Assign QR')).toBeNull();
    });

    /**
     * ⚠️ REUSES the existing endpoint, keyed on the ASSIGNMENT's uuid — its route
     * key — not the code's serial or id. A wrong key 404s at route binding, before
     * the permission check runs.
     */
    it('unassign calls the existing assignments.destroy route with the assignment uuid', () => {
        renderPage({ currentAssignment: assignment });
        fireEvent.click(screen.getByText('Unassign QR'));

        expect(deletes.length).toBe(1);
        expect(deletes[0].url).toContain('admin.qr.assignments.destroy');
        expect(deletes[0].url).toContain('a-uuid');
        expect(deletes[0].url).not.toContain('AX-000001');
    });

    /** ⚠️ Unassigning a live sticker has no undo, so it is confirmed first. */
    it('does not unassign when the confirm is declined', () => {
        window.confirm = vi.fn(() => false);
        renderPage({ currentAssignment: assignment });
        fireEvent.click(screen.getByText('Unassign QR'));

        expect(deletes.length).toBe(0);
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

describe('edit pencil on the assignment panel', () => {
    /** ⚠️ Nothing to edit without an assignment — every field it opens lives there. */
    it('is absent when the code is unassigned', () => {
        renderPage();
        expect(screen.queryByLabelText('Edit QR details')).toBeNull();
    });

    it('is present when the code is assigned', () => {
        renderPage({ currentAssignment: assignment });
        expect(screen.queryByLabelText('Edit QR details')).not.toBeNull();
    });

    /**
     * ⚠️ REUSES the Assignments page's modal rather than a second copy — asserted
     * by its title and by fields only that modal renders. A rebuilt form would
     * pass "a dialog opened" while drifting from the one it duplicates.
     */
    it('opens the shared Edit QR details modal, pre-filled from this assignment', () => {
        renderPage({ currentAssignment: assignment });
        fireEvent.click(screen.getByLabelText('Edit QR details'));

        expect(screen.getAllByText('Edit QR details').length).toBeGreaterThan(0);

        // The modal's read-only context line identifies WHICH sticker is edited.
        expect(screen.getByText(/AX-000001 · Acme Cafe/)).toBeTruthy();

        // Pre-filled from currentAssignment, not blank.
        expect(screen.getByDisplayValue('Front counter')).toBeTruthy();
        expect(screen.getByDisplayValue('Hi from table 4')).toBeTruthy();
    });

    it('offers the QR type placeholder from the shared key', () => {
        renderPage({ currentAssignment: assignment });
        fireEvent.click(screen.getByLabelText('Edit QR details'));
        expect(screen.getByText('Choose QR Type')).toBeTruthy();
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
