import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * displayStatus() had no 'failed' case. A Flow whose last sync failed fell
 * through to "Draft" by two routes, and both are pinned here:
 *
 *  - meta_flow_id set, meta_sync_status 'failed', no error text (the
 *    unreconcilable-status path in reconcileStatus) -> switch default.
 *  - meta_flow_id NULL, meta_sync_status 'failed', with an error message
 *    (fail() when no WABA is connected or Meta rejects createFlow) -> the
 *    early "never synced" return, which never reached the switch at all.
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

import FlowsIndex from '@/Pages/client/Flows/Index';

const flow = (overrides = {}) => ({
    uuid: 'flow-1',
    name: 'Lead capture',
    description: null,
    category: 'LEAD_GENERATION',
    status: 'draft',
    screens: [],
    submit_settings: null,
    meta_flow_id: null,
    meta_sync_status: null,
    meta_validation_errors: [],
    meta_sync_error: null,
    field_count: 0,
    step_count: 0,
    submissions_count: 0,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-02T00:00:00.000Z',
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    ...overrides,
});

const renderFlow = (overrides) => render(<FlowsIndex flows={[flow(overrides)]} categories={['LEAD_GENERATION']} />);

describe('a Flow whose sync failed is never shown as Draft', () => {
    it('shows the red Sync Failed pill when meta_flow_id is set and failed carries no error text', () => {
        renderFlow({ meta_flow_id: '1234', meta_sync_status: 'failed' });

        const pill = screen.getByText('Sync Failed');
        expect(pill.className).toMatch(/bg-red-50/);
        expect(pill.className).toMatch(/text-red-700/);
        expect(screen.queryByText('Draft')).not.toBeInTheDocument();
    });

    it('still explains itself when there is no error text to show', () => {
        renderFlow({ meta_flow_id: '1234', meta_sync_status: 'failed' });

        expect(screen.getByText(/last sync to Meta did not complete/i)).toBeInTheDocument();
    });

    it('shows Sync Failed with the real message when the Flow never got a meta_flow_id', () => {
        renderFlow({
            meta_flow_id: null,
            meta_sync_status: 'failed',
            meta_sync_error: 'Connect an active WhatsApp Business Account and phone number before syncing this Flow.',
        });

        expect(screen.getByText('Sync Failed').className).toMatch(/bg-red-50/);
        expect(screen.getByText(/Connect an active WhatsApp Business Account/)).toBeInTheDocument();
        expect(screen.queryByText('Draft')).not.toBeInTheDocument();
    });

    // POSITIVE CONTROLS — the same page must keep showing Draft where Draft is true,
    // otherwise the tests above would pass for a page that shows Sync Failed for everything.
    it('positive control: a never-synced flow with no failure is still Draft', () => {
        renderFlow({ meta_flow_id: null, meta_sync_status: null });

        expect(screen.getByText('Draft')).toBeInTheDocument();
        expect(screen.queryByText('Sync Failed')).not.toBeInTheDocument();
    });

    it('positive control: a synced draft is still Synced Draft, not Sync Failed', () => {
        renderFlow({ meta_flow_id: '1234', meta_sync_status: 'synced_draft' });

        // "Synced Draft" is both the pill and the banner headline — pick the pill.
        const pill = screen.getAllByText('Synced Draft').find((el) => /rounded-full/.test(el.className));
        expect(pill).toBeDefined();
        expect(screen.queryByText('Sync Failed')).not.toBeInTheDocument();
    });
});
