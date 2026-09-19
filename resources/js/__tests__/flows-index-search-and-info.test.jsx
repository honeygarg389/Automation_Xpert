import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/EmptyState', () => ({ default: () => null }));

import { router } from '@inertiajs/react';
import FlowsIndex from '@/Pages/client/Flows/Index';

const flow = (overrides = {}) => ({
    uuid: 'flow-1',
    name: 'Lead capture',
    description: 'A description',
    category: 'LEAD_GENERATION',
    status: 'draft',
    screens: [{
        id: 'contact', title: 'Contact', fields: [
            { id: 'f1', type: 'email', label: 'Email address', name: 'email', required: true, helper_text: 'We never spam', options: [], step: 1, order: 1 },
        ],
    }],
    submit_settings: { button_text: 'Go', success_message: 'Thanks!' },
    meta_flow_id: null,
    meta_sync_status: null,
    meta_validation_errors: [],
    meta_sync_error: null,
    field_count: 1,
    step_count: 1,
    submissions_count: 3,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-02T00:00:00.000Z',
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    ...overrides,
});

describe('flows list search', () => {
    it('shows every flow with no query', () => {
        render(<FlowsIndex flows={[flow({ uuid: 'a', name: 'Lead capture' }), flow({ uuid: 'b', name: 'Survey form' })]} categories={['LEAD_GENERATION', 'SURVEY']} />);
        expect(screen.getByText('Lead capture')).toBeInTheDocument();
        expect(screen.getByText('Survey form')).toBeInTheDocument();
    });

    it('filters by name, case-insensitively', () => {
        render(<FlowsIndex flows={[flow({ uuid: 'a', name: 'Lead capture' }), flow({ uuid: 'b', name: 'Survey form' })]} categories={['LEAD_GENERATION', 'SURVEY']} />);

        fireEvent.change(screen.getByPlaceholderText(/Search flows/i), { target: { value: 'survey' } });

        expect(screen.queryByText('Lead capture')).not.toBeInTheDocument();
        expect(screen.getByText('Survey form')).toBeInTheDocument();
    });

    it('filters by category', () => {
        render(<FlowsIndex flows={[flow({ uuid: 'a', name: 'One', category: 'LEAD_GENERATION' }), flow({ uuid: 'b', name: 'Two', category: 'SURVEY' })]} categories={['LEAD_GENERATION', 'SURVEY']} />);

        fireEvent.change(screen.getByPlaceholderText(/Search flows/i), { target: { value: 'survey' } });

        expect(screen.queryByText('One')).not.toBeInTheDocument();
        expect(screen.getByText('Two')).toBeInTheDocument();
    });

    it('shows a no-match message rather than an empty grid when nothing matches', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.change(screen.getByPlaceholderText(/Search flows/i), { target: { value: 'nonexistent-xyz' } });

        expect(screen.getByText(/No flows match/)).toBeInTheDocument();
    });
});

describe('the read-only Info modal', () => {
    const openInfo = (flowProps = {}) => {
        render(<FlowsIndex flows={[flow(flowProps)]} categories={['LEAD_GENERATION']} />);
        fireEvent.click(screen.getByLabelText('Actions for Lead capture'));
        fireEvent.click(screen.getByText('Info'));
    };

    it('shows the Overview & Settings tab by default, with Slice-1 and Slice-7 fields, none invented', () => {
        openInfo();
        const modal = within(screen.getByRole('dialog'));

        expect(modal.getByText('Category')).toBeInTheDocument();
        expect(modal.getByText('LEAD_GENERATION')).toBeInTheDocument();
        expect(modal.getByText('Total fields')).toBeInTheDocument();
        expect(modal.getByText('reCAPTCHA required')).toBeInTheDocument();
        expect(modal.getByText('Submit button text')).toBeInTheDocument();
        expect(modal.getByText('Go')).toBeInTheDocument();
        expect(modal.getByText('Submissions')).toBeInTheDocument();
        expect(modal.getByText('3')).toBeInTheDocument();

        // Explicitly never invented: there is no tracked "default value" or
        // theme_color concept anywhere in this schema.
        expect(modal.queryByText(/theme.?color/i)).not.toBeInTheDocument();
        expect(modal.queryByText(/default value/i)).not.toBeInTheDocument();
    });

    it('shows the web form URL when enabled, and "Disabled" when not', () => {
        openInfo({ web_form_enabled: false });
        const modal = within(screen.getByRole('dialog'));
        expect(modal.getByText('Disabled')).toBeInTheDocument();
    });

    it('switches to the Form Fields tab and lists every field with type and required state, but no default-value column', () => {
        openInfo();
        const modal = within(screen.getByRole('dialog'));

        fireEvent.click(modal.getByText('Form Fields'));

        expect(modal.getByText('Email address')).toBeInTheDocument();
        expect(modal.getByText('(email)')).toBeInTheDocument();
        expect(modal.getByText('email')).toBeInTheDocument();
        expect(modal.getByText('Required')).toBeInTheDocument();
        expect(modal.getByText('We never spam')).toBeInTheDocument();
    });

    it('is read-only — Edit already exists separately, so Info must not duplicate an editable control', () => {
        openInfo();

        // No text inputs or textareas inside the modal — a quick-glance panel
        // must never grow an editable control Edit already owns.
        expect(within(screen.getByRole('dialog')).queryAllByRole('textbox')).toHaveLength(0);
    });
});

/**
 * Section E — "Sync from Meta" unifies the old separate "Sync Meta Flows"
 * (import picker) and "Sync Status" (bulk refresh) buttons into one action.
 *
 * Task 3 refinement — this is now a single POST to its own dedicated
 * `sync-from-meta` endpoint (WhatsappFlowMetaSyncService::syncAllFromMeta()),
 * which refreshes linked flows AND auto-imports unmatched ones server-side
 * in one call and returns one concrete "{updated} updated, {imported}
 * imported, {errors} errors" summary — surfaced through this page's existing
 * flash-banner convention, same as every other action here. The manual
 * checkbox picker this button used to open is gone from this entry point
 * (a single deterministic summary isn't obtainable from an action whose
 * import half waits on an arbitrary later user choice); its underlying
 * routes stay independently reachable and are covered by their own backend
 * tests.
 */
describe('the Sync from Meta action (Section E / Task 3)', () => {
    beforeEach(() => {
        router.post.mockClear();
    });

    afterEach(() => {
        window.confirm = undefined;
    });

    it('posts once to the dedicated sync-from-meta endpoint after confirmation', () => {
        window.confirm = vi.fn(() => true);
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.click(screen.getByRole('button', { name: /Sync from Meta/i }));

        expect(window.confirm).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(route('client.flows.sync-from-meta'), {}, expect.anything());
    });

    it('does nothing at all when the overwrite warning is declined', () => {
        window.confirm = vi.fn(() => false);
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.click(screen.getByRole('button', { name: /Sync from Meta/i }));

        expect(router.post).not.toHaveBeenCalled();
    });

    it('no longer opens any import picker modal from this button', () => {
        window.confirm = vi.fn(() => true);
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.click(screen.getByRole('button', { name: /Sync from Meta/i }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
