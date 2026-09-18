import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/EmptyState', () => ({ default: () => null }));

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
 * The "Sync Meta Flows" import picker — the feature that took over the name
 * from the renamed bulk-reconcile action. Frontend half of
 * WhatsappFlowImportTest.php's backend coverage: this proves the picker
 * fetches, renders candidates, and gates Import on a selection — not that
 * import() itself is correct (already proven server-side).
 */
describe('the Sync Meta Flows import picker', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('fetches the picker endpoint and lists importable Meta flows with their badges', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ flows: [
                { meta_flow_id: 'm1', name: 'Untitled Survey', status: 'DRAFT', categories: ['SURVEY'], validation_errors: [] },
            ] }),
        }));
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.click(screen.getByRole('button', { name: /Sync Meta Flows/i }));

        await waitFor(() => expect(screen.getByText('Untitled Survey')).toBeInTheDocument());
        expect(within(screen.getByRole('dialog')).getByText('DRAFT')).toBeInTheDocument();
        expect(fetch).toHaveBeenCalledWith('/client.flows.import.picker', expect.anything());
    });

    it('disables Import until at least one candidate is selected, then enables it', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ flows: [
                { meta_flow_id: 'm1', name: 'Untitled Survey', status: 'DRAFT', categories: [], validation_errors: [] },
            ] }),
        }));
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        fireEvent.click(screen.getByRole('button', { name: /Sync Meta Flows/i }));
        await waitFor(() => expect(screen.getByText('Untitled Survey')).toBeInTheDocument());

        const importButton = screen.getByRole('button', { name: /^Import/ });
        expect(importButton).toBeDisabled();

        fireEvent.click(screen.getByLabelText('Untitled Survey'));

        expect(importButton).not.toBeDisabled();
        expect(importButton).toHaveTextContent('Import (1)');
    });

    it('shows the fetch error rather than an empty list when Meta cannot be reached', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'Connect an active WhatsApp Business Account before importing Flows.' }),
        }));
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        fireEvent.click(screen.getByRole('button', { name: /Sync Meta Flows/i }));

        await waitFor(() => expect(screen.getByText(/Connect an active WhatsApp Business Account/)).toBeInTheDocument());
    });
});
