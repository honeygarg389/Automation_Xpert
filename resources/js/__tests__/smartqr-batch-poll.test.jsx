import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ WHAT THIS FILE PINS — the batch detail page's auto-refresh.
 *
 *   starts   only while the batch is draft/generating OR a part is
 *            queued/processing
 *   stops    once everything has settled — INCLUDING on `printed` and `retired`,
 *            statuses that a deny-list guard ("stop at generated/failed") would
 *            poll forever on
 *   asks     for exactly the three props show() renders, every 5000ms
 *   cleans   up on unmount, so navigating away does not leave a timer running
 *   gives up after MAX_POLL_ATTEMPTS and says so, instead of hiding a dead
 *            queue behind an animation that never resolves
 *
 * ⚠️ TIMER TESTS ASSERT CALL COUNTS ACROSS A TICK BOUNDARY, not "a timer was
 * registered". `expect(setInterval).toHaveBeenCalled()` passes on a poll that
 * reloads the wrong props, at the wrong interval, and never stops.
 */

const reloads = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { permissions: ['manage_qr_batches', 'view_qr_inventory'] }, flash: {}, errors: {}, timezone: 'UTC' },
        url: '/admin/qr/batches/x',
    }),
    router: {
        reload: (opts) => reloads.push(opts),
        post: vi.fn(), get: vi.fn(), delete: vi.fn(),
    },
    useForm: (initial) => ({ data: initial, setData: vi.fn(), post: vi.fn(), patch: vi.fn(), processing: false, errors: {}, reset: vi.fn(), clearErrors: vi.fn() }),
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

/**
 * ⚠️ READS THE REAL en.json, like the sibling export-panel file. A mock that
 * echoed the key back would make the "still processing" assertion pass against
 * a missing translation — the exact failure recorded in CLAUDE.md as
 * "translation key vs rendered label".
 */
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

const POLL_MS = 5000;
const MAX_ATTEMPTS = 60;

const makeBatch = (status) => ({
    id: 1, uuid: 'batch-uuid-1', batch_name: 'B', batch_number: 'AX-BK-1', prefix: 'AX',
    quantity: 10, serial_start: 1, status, generated_count: 0, printed_count: 0,
    assigned_count: 0, active_count: 0, created_at: '2026-01-01', failure_reason: null,
});

const renderPage = (status, exports = []) =>
    render(
        <SmartQrBatchShow
            batch={makeBatch(status)}
            codes={{ data: [], links: [], current_page: 1, last_page: 1, total: 0 }}
            exports={exports}
        />,
    );

const part = (status, n = 1) => ({
    id: n, part_number: n, total_parts: 1, format: 'svg', status,
    path: status === 'ready' ? 'x.zip' : null, error: null, updated_at: '2026-01-01',
});

/** Advance N whole poll intervals inside act(), so React flushes each tick. */
const tick = (intervals = 1) => {
    for (let i = 0; i < intervals; i++) {
        act(() => { vi.advanceTimersByTime(POLL_MS); });
    }
};

beforeEach(() => { reloads.length = 0; vi.useFakeTimers(); });
afterEach(() => { vi.useRealTimers(); });

describe('polls while work is in flight', () => {
    it('polls while the batch is generating', () => {
        renderPage('generating');
        tick(1);
        expect(reloads).toHaveLength(1);
    });

    it('polls while the batch is draft (dispatched, not yet picked up)', () => {
        renderPage('draft');
        tick(1);
        expect(reloads).toHaveLength(1);
    });

    it('polls while an export part is queued, even with the batch settled', () => {
        renderPage('generated', [part('queued')]);
        tick(1);
        expect(reloads).toHaveLength(1);
    });

    it('polls while an export part is processing', () => {
        renderPage('generated', [part('processing')]);
        tick(1);
        expect(reloads).toHaveLength(1);
    });
});

describe('does not poll once everything has settled', () => {
    it('does not poll a generated batch with no exports', () => {
        renderPage('generated');
        tick(3);
        expect(reloads).toHaveLength(0);
    });

    it('does not poll a failed batch', () => {
        renderPage('failed');
        tick(3);
        expect(reloads).toHaveLength(0);
    });

    /**
     * ⚠️ THE REGRESSION THIS FILE EXISTS FOR. `printed` is the fifth batch
     * status. A guard written as "keep polling until generated or failed"
     * reaches this state and never stops — hammering the server for the entire
     * time an admin leaves a finished batch open.
     */
    it('does not poll a PRINTED batch — the status a deny-list guard would miss', () => {
        renderPage('printed');
        tick(5);
        expect(reloads).toHaveLength(0);
    });

    it('renders a retired batch as settled and hides the redundant Retire action', () => {
        renderPage('retired');
        tick(5);

        expect(reloads).toHaveLength(0);

        const badge = screen.getByText('Retired');
        expect(badge.className).toContain('bg-coral-50');
        expect(screen.queryByText('smart_qr.batch_status.retired')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Retire batch' })).toBeNull();
    });

    it('does not poll when every part is ready or failed', () => {
        renderPage('generated', [part('ready', 1), part('failed', 2)]);
        tick(3);
        expect(reloads).toHaveLength(0);
    });
});

describe('what it asks the server for', () => {
    it('requests exactly the three props show() renders', () => {
        renderPage('generating');
        tick(1);
        expect(reloads[0].only).toEqual(['batch', 'codes', 'exports']);
    });

    it('preserves scroll and local state so the page does not jump under the admin', () => {
        renderPage('generating');
        tick(1);
        expect(reloads[0].preserveScroll).toBe(true);
        expect(reloads[0].preserveState).toBe(true);
    });

    /**
     * Pins the INTERVAL, not merely that a timer exists: at 4999ms nothing has
     * been asked for, at 5000ms exactly one request has.
     */
    it('fires on a 5000ms period, not sooner', () => {
        renderPage('generating');
        act(() => { vi.advanceTimersByTime(POLL_MS - 1); });
        expect(reloads).toHaveLength(0);
        act(() => { vi.advanceTimersByTime(1); });
        expect(reloads).toHaveLength(1);
    });

    it('keeps polling on subsequent ticks', () => {
        renderPage('generating');
        tick(3);
        expect(reloads).toHaveLength(3);
    });
});

describe('cleanup', () => {
    /**
     * ⚠️ THE BUG THAT ONLY APPEARS ON A REAL NAVIGATION. A missing
     * clearInterval leaves the timer reloading a page the admin has left,
     * forever, and nothing on screen shows it.
     */
    it('clears the interval on unmount', () => {
        const { unmount } = renderPage('generating');
        tick(2);
        expect(reloads).toHaveLength(2);

        unmount();
        tick(5);

        expect(reloads).toHaveLength(2);
    });
});

describe('attempt cap', () => {
    it('stops after MAX_POLL_ATTEMPTS when the status never changes', () => {
        renderPage('generating');
        tick(MAX_ATTEMPTS + 10);
        expect(reloads).toHaveLength(MAX_ATTEMPTS);
    });

    it('shows the still-processing note once it gives up', () => {
        renderPage('generating');
        expect(screen.queryByRole('status')).toBeNull();

        tick(MAX_ATTEMPTS + 1);

        const note = screen.getByRole('status');
        expect(note.textContent).toContain('Still processing');
        expect(note.textContent).not.toBe('smart_qr.poll_stalled');
    });

    it('shows no note while polling is still healthy', () => {
        renderPage('generating');
        tick(5);
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('shows no note on a settled batch', () => {
        renderPage('printed');
        tick(3);
        expect(screen.queryByRole('status')).toBeNull();
    });
});
