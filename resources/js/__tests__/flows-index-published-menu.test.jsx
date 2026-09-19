import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

/**
 * Section F (Published-flow dashboard menu) and Section G (the Deprecate
 * confirmation copy for a Published flow, as distinct from a routine
 * Delete). Section H's Duplicate item is covered for both menu variants.
 */
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => (o?.word ? `${k}:${o.word}` : k) }),
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
            { id: 'f1', type: 'email', label: 'Email address', name: 'email', required: true, helper_text: null, options: [], step: 1, order: 1 },
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

const openMenu = () => fireEvent.click(screen.getByLabelText('Actions for Lead capture'));

describe('a Published flow\'s ⋮ menu (Section F)', () => {
    const publishedFlow = () => flow({ meta_flow_id: 'meta-1', meta_sync_status: 'published', status: 'published' });

    it('drops Edit, Sync Draft to Meta, and Publish to Meta entirely', () => {
        render(<FlowsIndex flows={[publishedFlow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = within(screen.getByTestId('card-actions-menu'));
        expect(menu.queryByText('Edit')).not.toBeInTheDocument();
        expect(menu.queryByText('Sync Draft to Meta')).not.toBeInTheDocument();
        expect(menu.queryByText('Publish to Meta')).not.toBeInTheDocument();
    });

    it('offers Preview, Send Test, Duplicate, and Deprecate instead — and drops the redundant per-card Sync from Meta (Section P)', () => {
        render(<FlowsIndex flows={[publishedFlow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = within(screen.getByTestId('card-actions-menu'));
        expect(menu.getByText('Preview')).toBeInTheDocument();
        expect(menu.getByText('Send Test')).toBeInTheDocument();
        // "Duplicate" — Section O's "Create New Version" label was reverted;
        // the clone_flow_id-based logic underneath is unchanged, but the
        // label is now "Duplicate" everywhere, regardless of published state.
        expect(menu.getByText('Duplicate')).toBeInTheDocument();
        expect(menu.getByText('Deprecate')).toBeInTheDocument();
        expect(menu.queryByText('Create New Version')).not.toBeInTheDocument();
        expect(menu.queryByText('Sync from Meta')).not.toBeInTheDocument();
        expect(menu.queryByText('Delete')).not.toBeInTheDocument();
    });

    it('Duplicate posts to the duplicate route for this flow (same clone_flow_id logic as any other Duplicate)', () => {
        render(<FlowsIndex flows={[publishedFlow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        fireEvent.click(within(screen.getByTestId('card-actions-menu')).getByText('Duplicate'));

        expect(router.post).toHaveBeenCalledWith(route('client.flows.duplicate', 'flow-1'), {}, expect.anything());
    });

    it('Deprecate opens a materially stronger, irreversible-specific confirmation — not the routine Delete copy', () => {
        render(<FlowsIndex flows={[publishedFlow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        fireEvent.click(within(screen.getByTestId('card-actions-menu')).getByText('Deprecate'));

        expect(screen.getByText('Deprecate this published Flow?')).toBeInTheDocument();
        const dialog = within(screen.getByRole('dialog'));
        expect(dialog.getByText(/PERMANENT and cannot be undone/)).toBeInTheDocument();
        expect(dialog.getByText(/does not offer any way to un-deprecate/)).toBeInTheDocument();
        // Never claims the Flow is removed — it stays, per Section G's contract.
        expect(dialog.queryByText(/permanently removes/)).not.toBeInTheDocument();
        expect(dialog.getByLabelText('common.type_to_confirm:DEPRECATE')).toBeInTheDocument();
    });

    it('only calls destroy() once DEPRECATE (not DELETE) is typed and confirmed', () => {
        render(<FlowsIndex flows={[publishedFlow()]} categories={['LEAD_GENERATION']} />);
        openMenu();
        fireEvent.click(within(screen.getByTestId('card-actions-menu')).getByText('Deprecate'));

        const input = screen.getByLabelText('common.type_to_confirm:DEPRECATE');
        const confirmButton = screen.getByText('Deprecate', { selector: 'button' });

        fireEvent.change(input, { target: { value: 'DELETE' } });
        expect(confirmButton).toBeDisabled();

        fireEvent.change(input, { target: { value: 'DEPRECATE' } });
        expect(confirmButton).not.toBeDisabled();
        fireEvent.click(confirmButton);

        expect(router.delete).toHaveBeenCalledWith(route('client.flows.destroy', 'flow-1'), expect.anything());
    });
});

describe('a non-Published flow\'s ⋮ menu keeps the full existing set plus Duplicate', () => {
    it('still shows Edit, Sync Draft to Meta, Publish to Meta, and the routine Delete', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = within(screen.getByTestId('card-actions-menu'));
        expect(menu.getByText('Edit')).toBeInTheDocument();
        expect(menu.getByText('Sync Draft to Meta')).toBeInTheDocument();
        expect(menu.getByText('Publish to Meta')).toBeInTheDocument();
        expect(menu.getByText('Duplicate')).toBeInTheDocument();
        expect(menu.getByText('Delete')).toBeInTheDocument();
        expect(menu.queryByText('Deprecate')).not.toBeInTheDocument();
    });
});

/**
 * Section N — the badge and the panel banner must derive from the SAME
 * single source, so they can never contradict each other. Before this, the
 * badge read local `flow.status` and the banner read `meta_sync_status` —
 * two different fields, so a flow whose local status was "published" without
 * ever successfully syncing could show a "PUBLISHED" badge right next to a
 * "Not yet live on Meta platform" panel.
 */
describe('the status badge and panel text never contradict each other (Section N)', () => {
    it('a flow truly published on Meta shows PUBLISHED consistently in both the badge and the panel', () => {
        render(<FlowsIndex flows={[flow({ status: 'published', meta_flow_id: 'meta-1', meta_sync_status: 'published' })]} categories={['LEAD_GENERATION']} />);

        expect(screen.getByText('Published')).toBeInTheDocument();
        expect(screen.getByText('Published to Meta')).toBeInTheDocument();
        expect(screen.queryByText(/not yet live/i)).not.toBeInTheDocument();
    });

    it('a flow with local status "published" but never synced to Meta does NOT show a contradicting panel — the never-synced case has no Meta truth to defer to', () => {
        render(<FlowsIndex flows={[flow({ status: 'published', meta_flow_id: null, meta_sync_status: null })]} categories={['LEAD_GENERATION']} />);

        // The badge reflects local intent (there is no Meta state yet to override it)...
        expect(screen.getByText('Published')).toBeInTheDocument();
        // ...but the panel must say something CONSISTENT with that, not "Draft Status / Not yet live" — the old contradiction this section fixes.
        expect(screen.queryByText('Draft Status')).not.toBeInTheDocument();
        expect(screen.getByText('Published Status')).toBeInTheDocument();
    });

    it('a linked flow whose local status is stale relative to a failed Meta sync shows the failure, not a false Published claim', () => {
        render(<FlowsIndex flows={[flow({ status: 'published', meta_flow_id: 'meta-1', meta_sync_status: 'failed', meta_sync_error: 'Meta rejected the upload.' })]} categories={['LEAD_GENERATION']} />);

        // Once linked, Meta's own last-known state is authoritative — a sync failure must be visible, not hidden behind a stale local "published".
        expect(screen.getByText('Sync Failed')).toBeInTheDocument();
        expect(screen.getByText('Sync Error')).toBeInTheDocument();
        expect(screen.getByText('Meta rejected the upload.')).toBeInTheDocument();
        expect(screen.queryByText('Published')).not.toBeInTheDocument();
    });

    it('a draft, never-synced flow shows Draft consistently', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);

        expect(screen.getByText('Draft')).toBeInTheDocument();
        expect(screen.getByText('Draft Status')).toBeInTheDocument();
        expect(screen.getByText('Not yet live on Meta platform')).toBeInTheDocument();
    });
});

/** Section P — the ⋮ menu's item padding is reduced slightly from the codebase's existing scale (py-2 -> py-1.5). */
describe('the ⋮ dropdown menu\'s item spacing (Section P)', () => {
    it('uses the tighter py-1.5 vertical padding, not the old py-2', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = screen.getByTestId('card-actions-menu');
        const editItem = within(menu).getByText('Edit').closest('a, button');
        expect(editItem.className).toMatch(/\bpy-1\.5\b/);
        expect(editItem.className).not.toMatch(/\bpy-2\b/);
    });
});
