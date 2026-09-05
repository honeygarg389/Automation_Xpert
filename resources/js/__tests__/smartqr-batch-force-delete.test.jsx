import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS — the local-only Force Delete control.
 *
 *   the gate      absent unless BOTH forceDeleteAvailable and canManage hold,
 *                 asserted as three separate cases so one flag masking the
 *                 other cannot pass
 *   the typing    the confirm button stays disabled until "FORCE DELETE" is
 *                 typed EXACTLY — near-misses are checked, not just the match
 *   the target    confirming issues a DELETE to the forceDestroy route, not to
 *                 the ordinary destroy route beside it
 *
 * ⚠️ The server is the real gate (route registered only on local, plus
 * abort_unless in the controller). Nothing here can enforce that — this only
 * pins that the UI does not OFFER a control the server would 404.
 */

const deletes = [];
let grantedPermissions = ['manage_qr_batches'];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { permissions: grantedPermissions }, flash: {}, errors: {}, timezone: 'UTC' },
        url: '/admin/qr/batches',
    }),
    router: {
        delete: (url, opts) => deletes.push({ url, opts }),
        post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn(),
    },
    useForm: (initial) => ({
        data: initial, setData: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(),
        processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn(), transform: vi.fn(),
    }),
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

/** Resolves against the real en.json — a missing key fails as a raw string. */
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

const BATCH = {
    id: 1,
    uuid: 'batch-uuid-1',
    batch_number: 'AX-BK-001',
    batch_name: 'Business Kit',
    prefix: 'AX',
    quantity: 10,
    status: 'generated',
    codes_count: 10,
    assigned_count: 0,
    created_at: '2026-08-27T05:03:09Z',
};

const paginator = (rows) => ({ data: rows, links: [], current_page: 1, last_page: 1, total: rows.length });

const loadIndex = async () => (await import('@/Pages/Admin/SmartQr/Batches/Index')).default;

beforeEach(() => {
    deletes.length = 0;
    grantedPermissions = ['manage_qr_batches'];
    global.route = (name, param) => `/__route__/${name}/${param ?? ''}`;
});

afterEach(() => vi.clearAllMocks());

describe('Batches/Index — Force Delete visibility gate', () => {
    it('is absent when forceDeleteAvailable is false, even with manage permission', async () => {
        const Index = await loadIndex();
        render(<Index batches={paginator([BATCH])} forceDeleteAvailable={false} />);

        expect(screen.queryByText('DEV ONLY')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Force Delete')).not.toBeInTheDocument();
    });

    /** ⚠️ Defaulted, not just explicitly false — an omitted prop must not open it. */
    it('is absent when the prop is omitted entirely', async () => {
        const Index = await loadIndex();
        render(<Index batches={paginator([BATCH])} />);

        expect(screen.queryByText('DEV ONLY')).not.toBeInTheDocument();
    });

    it('is absent when available but the admin lacks manage_qr_batches', async () => {
        grantedPermissions = ['view_qr_inventory'];

        const Index = await loadIndex();
        render(<Index batches={paginator([BATCH])} forceDeleteAvailable />);

        expect(screen.queryByText('DEV ONLY')).not.toBeInTheDocument();
    });

    it('is present only when available AND permitted', async () => {
        const Index = await loadIndex();
        render(<Index batches={paginator([BATCH])} forceDeleteAvailable />);

        expect(screen.getByText('DEV ONLY')).toBeInTheDocument();
        expect(screen.getByLabelText('Force Delete')).toBeInTheDocument();
    });
});

describe('Batches/Index — Force Delete typed confirmation', () => {
    const openModal = async () => {
        const Index = await loadIndex();
        render(<Index batches={paginator([BATCH])} forceDeleteAvailable />);
        fireEvent.click(screen.getByLabelText('Force Delete'));
    };

    it('opens a modal whose copy names what is destroyed', async () => {
        await openModal();

        expect(screen.getByText('Force delete batch — permanent')).toBeInTheDocument();
        expect(screen.getByText(/scan history, attribution and daily statistics/)).toBeInTheDocument();
        expect(screen.getByText(/Type FORCE DELETE to confirm/)).toBeInTheDocument();
    });

    /**
     * ⚠️ NEAR-MISSES ARE THE POINT. Asserting only "empty is disabled" would
     * pass against a modal that accepted any non-empty string, which is not a
     * typed confirmation at all.
     */
    it('keeps confirm disabled until the exact phrase is typed', async () => {
        await openModal();

        const input = screen.getByLabelText(/Type FORCE DELETE to confirm/);
        const button = screen.getByRole('button', { name: 'Permanently destroy' });

        expect(button).toBeDisabled();

        for (const wrong of ['DELETE', 'force delete', 'FORCE  DELETE', 'FORCE DELETE ', 'FORCEDELETE']) {
            fireEvent.change(input, { target: { value: wrong } });
            expect(button, `"${wrong}" must not arm the button`).toBeDisabled();
        }

        fireEvent.change(input, { target: { value: 'FORCE DELETE' } });
        expect(button).toBeEnabled();
    });

    it('sends DELETE to the forceDestroy route, not the ordinary destroy route', async () => {
        await openModal();

        fireEvent.change(screen.getByLabelText(/Type FORCE DELETE to confirm/), {
            target: { value: 'FORCE DELETE' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Permanently destroy' }));

        expect(deletes).toHaveLength(1);
        expect(deletes[0].url).toBe('/__route__/admin.qr.batches.forceDestroy/batch-uuid-1');
        expect(deletes[0].url).not.toContain('batches.destroy');
    });

    it('sends nothing if the modal is cancelled', async () => {
        await openModal();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(deletes).toHaveLength(0);
    });
});
