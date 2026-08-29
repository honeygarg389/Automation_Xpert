import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from 'react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS — the "more QR's" control.
 *
 *   the button   appears only for a generated/failed batch, and only with
 *                manage_qr_batches — HIDDEN, not disabled, matching Retire
 *   the preview  shows the serials that will actually be created, recomputed
 *                live as the number changes
 *   the maths    first new serial is serial_start + quantity — NOT quantity + 1
 *                and NOT serial_start + quantity + 1, the two off-by-ones that
 *                look right on a batch starting at 1
 *
 * ⚠️ THE PREVIEW ASSERTIONS READ RENDERED TEXT, not props. The preview is the
 * only thing telling an admin which serials they are committing to print, and a
 * component that computed it correctly but rendered `{{first}}` unsubstituted
 * would pass any structural check.
 */

const posts = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { permissions: ['manage_qr_batches', 'view_qr_inventory'] }, flash: {}, errors: {}, timezone: 'UTC' },
        url: '/admin/qr/batches/x',
    }),
    router: { reload: vi.fn(), post: vi.fn(), get: vi.fn(), delete: vi.fn() },
    useForm: (initial) => {
        // ⚠️ A REAL useState, not a static object. The range preview is the
        // thing under test and it only updates if setData actually re-renders —
        // a stubbed setData would make every "recomputes live" assertion pass
        // against a component that never changes.
        const [data, set] = useState(initial);
        return {
            data,
            setData: (k, v) => set((d) => ({ ...d, [k]: v })),
            post: (url, opts) => posts.push({ url, data, opts }),
            patch: vi.fn(), processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn(),
        };
    },
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

const makeBatch = (over = {}) => ({
    id: 1, uuid: 'batch-uuid-1', batch_name: 'B', batch_number: 'AX-BK-1', prefix: 'AX',
    quantity: 10, serial_start: 1, status: 'generated', generated_count: 10, printed_count: 0,
    assigned_count: 0, active_count: 0, created_at: '2026-01-01', failure_reason: null, ...over,
});

const renderPage = (over = {}) =>
    render(
        <SmartQrBatchShow
            batch={makeBatch(over)}
            codes={{ data: [], links: [], current_page: 1, last_page: 1, total: 0 }}
            exports={[]}
        />,
    );

const openModal = () => fireEvent.click(screen.getByRole('button', { name: /more QR/i }));
const quantityInput = () => screen.getByLabelText(/how many more/i);

beforeEach(() => { posts.length = 0; });

describe('the "more QR\'s" button', () => {
    it('is shown for a generated batch', () => {
        renderPage({ status: 'generated' });
        expect(screen.queryByRole('button', { name: /more QR/i })).not.toBeNull();
    });

    it('is shown for a failed batch — extending is how you retry one', () => {
        renderPage({ status: 'failed' });
        expect(screen.queryByRole('button', { name: /more QR/i })).not.toBeNull();
    });

    /**
     * ⚠️ These three mirror QrBatchController::addCodes's gate. draft and
     * generating already have a job in flight; printed has no writer at all.
     */
    it.each(['draft', 'generating', 'printed'])('is hidden for a %s batch', (status) => {
        renderPage({ status });
        expect(screen.queryByRole('button', { name: /more QR/i })).toBeNull();
    });
});

describe('the range preview', () => {
    it('shows no preview before a number is entered', () => {
        renderPage();
        openModal();
        expect(screen.queryByText(/Will create/i)).toBeNull();
    });

    /**
     * ⚠️ THE OFF-BY-ONE. Batch is serial_start=1, quantity=10 — so it owns
     * AX-000001..AX-000010 and the next code is AX-000011, not AX-000010
     * (reusing the last) and not AX-000012 (skipping one).
     */
    it('starts the new range one past the batch current end', () => {
        renderPage({ serial_start: 1, quantity: 10 });
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '50' } });

        expect(screen.getByText(/Will create/i).textContent)
            .toBe('Will create AX-000011 – AX-000060');
    });

    /**
     * ⚠️ A batch NOT starting at 1 — where serial_start + quantity and
     * quantity + 1 give different answers, so an implementation that ignored
     * serial_start still passes the test above but fails this one.
     */
    it('respects a serial_start other than 1', () => {
        renderPage({ serial_start: 500, quantity: 20 });
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '5' } });

        expect(screen.getByText(/Will create/i).textContent)
            .toBe('Will create AX-000520 – AX-000524');
    });

    it('recomputes live as the number changes', () => {
        renderPage({ serial_start: 1, quantity: 10 });
        openModal();

        fireEvent.change(quantityInput(), { target: { value: '5' } });
        expect(screen.getByText(/Will create/i).textContent).toBe('Will create AX-000011 – AX-000015');

        fireEvent.change(quantityInput(), { target: { value: '2' } });
        expect(screen.getByText(/Will create/i).textContent).toBe('Will create AX-000011 – AX-000012');
    });

    it('shows a single-serial range for one extra code', () => {
        renderPage({ serial_start: 1, quantity: 10 });
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '1' } });
        expect(screen.getByText(/Will create/i).textContent).toBe('Will create AX-000011 – AX-000011');
    });

    /**
     * ⚠️ %06d does not truncate, and neither may the preview. A batch crossing
     * 999999 prints 7-digit serials; a preview padded to a hard 6 would disagree
     * with the sticker.
     */
    it('does not truncate serials past six digits', () => {
        renderPage({ serial_start: 999_998, quantity: 3 });
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '2' } });
        expect(screen.getByText(/Will create/i).textContent)
            .toBe('Will create AX-1000001 – AX-1000002');
    });

    it('hides the preview again for a non-positive or unparseable value', () => {
        renderPage();
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '5' } });
        expect(screen.queryByText(/Will create/i)).not.toBeNull();

        fireEvent.change(quantityInput(), { target: { value: '0' } });
        expect(screen.queryByText(/Will create/i)).toBeNull();

        fireEvent.change(quantityInput(), { target: { value: '' } });
        expect(screen.queryByText(/Will create/i)).toBeNull();
    });
});

describe('submission', () => {
    it('posts the additional quantity to the add-codes route', () => {
        renderPage();
        openModal();
        fireEvent.change(quantityInput(), { target: { value: '40' } });

        // ⚠️ The header trigger and the modal's submit carry the SAME label, so
        // getByRole would match two. Selected by type="submit" — the one inside
        // the form — rather than by position, which would silently follow a
        // future reorder.
        const submit = screen
            .getAllByRole('button', { name: /more QR/i })
            .find((b) => b.getAttribute('type') === 'submit');

        expect(submit).toBeDefined();
        fireEvent.click(submit);

        // ⚠️ Asserted the way the sibling export-panel test does: setup.jsx's
        // route() stub returns `/{name}/{json params}`, not a real path, so the
        // ROUTE NAME and the batch uuid are what there is to check.
        expect(posts.length).toBeGreaterThan(0);
        expect(posts[0].url).toContain('admin.qr.batches.add-codes');
        expect(posts[0].url).toContain('batch-uuid-1');
        expect(posts[0].url).not.toContain('update');
        expect(posts[0].data.additional_quantity).toBe('40');
    });
});
