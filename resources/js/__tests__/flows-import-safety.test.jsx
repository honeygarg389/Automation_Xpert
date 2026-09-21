import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { useState } from 'react';

/**
 * Frontend half of the import-safety guard (the server refuses regardless — see
 * WhatsappFlowImportSafetyTest). A Flow whose Meta content could not be fully
 * represented is left holding PLACEHOLDER screens locally; "Sync Draft to Meta"
 * or "Publish to Meta" would upload that placeholder over the real content.
 * The buttons are disabled with the reason shown, and the card no longer calls
 * a healthy Flow "Sync Failed" — nothing failed to sync, it just can't be edited.
 */
const routerPost = vi.fn();
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: (...args) => routerPost(...args), put: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: (initial) => {
        const [data, set] = useState(initial);
        return { data, setData: (k, v) => set((prev) => ({ ...prev, [k]: v })), put: vi.fn(), processing: false, errors: {} };
    },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => (o?.word ? `${k}:${o.word}` : k) }),
}));
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/EmptyState', () => ({ default: () => null }));

import FlowsIndex from '@/Pages/client/Flows/Index';
import FlowsBuilder from '@/Pages/client/Flows/Builder';

const REASON = 'Screen "FORM_SCREEN" is a final screen that also collects input fields (client_name, phone_1). Not supported for editing yet.';
const GUARD = 'This Flow\'s imported content could not be fully represented and cannot be synced until support is added — contact support or recreate this Flow\'s content manually.';
const FIELD_TYPES = ['heading', 'text', 'number', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox', 'date'];

const flow = (overrides = {}) => ({
    uuid: 'flow-1',
    name: 'Lead',
    description: null,
    category: 'LEAD_GENERATION',
    status: 'draft',
    screens: [{ id: 'step_1', title: 'Imported from Meta', fields: [{ id: 'field', type: 'text', label: 'Field', name: 'field', required: false, helper_text: null, options: [], step: 1, order: 1 }] }],
    submit_settings: { button_text: 'Submit', success_message: 'Thanks' },
    meta_flow_id: 'meta-1',
    meta_sync_status: 'synced_draft',
    meta_validation_errors: [],
    meta_sync_error: null,
    import_unsupported_reason: null,
    import_guard_message: null,
    field_count: 1,
    step_count: 1,
    submissions_count: 0,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-02T00:00:00.000Z',
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    max_submissions: null,
    limit_error_message: 'You have already reached the maximum number of submissions for this form.',
    ...overrides,
});

const lossy = { import_unsupported_reason: REASON, import_guard_message: GUARD, meta_sync_error: REASON };
const openMenu = () => fireEvent.click(screen.getByLabelText('Actions for Lead'));
const menu = () => within(screen.getByTestId('card-actions-menu'));

beforeEach(() => routerPost.mockClear());

describe('dashboard card — a lossy import', () => {
    it('disables Sync Draft to Meta and Publish to Meta and says why, and does not post when clicked', () => {
        render(<FlowsIndex flows={[flow({ ...lossy })]} categories={['LEAD_GENERATION']} />);
        openMenu();

        for (const label of ['Sync Draft to Meta', 'Publish to Meta']) {
            const button = menu().getByText(label).closest('button');
            expect(button, label).toBeDisabled();
            expect(button.getAttribute('title'), label).toMatch(/cannot be synced until support is added/i);
            fireEvent.click(button);
        }
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('shows the specific reason and an "editing not supported" state instead of "Sync Failed"', () => {
        render(<FlowsIndex flows={[flow({ ...lossy })]} categories={['LEAD_GENERATION']} />);

        expect(screen.getByText('Editing not supported')).toBeInTheDocument();
        expect(screen.getByText(REASON)).toBeInTheDocument();
        expect(screen.queryByText('Sync Failed')).not.toBeInTheDocument();
    });

    it('keeps the pill truthful about Meta: a healthy PUBLISHED flow still reads Published', () => {
        render(<FlowsIndex flows={[flow({ status: 'published', meta_sync_status: 'published', ...lossy })]} categories={['LEAD_GENERATION']} />);

        const pill = screen.getAllByText('Published').find((el) => /rounded-full/.test(el.className));
        expect(pill).toBeDefined();
        expect(screen.queryByText('Sync Failed')).not.toBeInTheDocument();
    });

    // POSITIVE CONTROLS — the same menu for a normal Flow must stay live, or the
    // disabled assertions above would pass for a menu that is disabled for everyone.
    it('positive control: a normal draft keeps both actions enabled and they still post', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const sync = menu().getByText('Sync Draft to Meta').closest('button');
        expect(sync).not.toBeDisabled();
        expect(menu().getByText('Publish to Meta').closest('button')).not.toBeDisabled();
        fireEvent.click(sync);
        expect(routerPost).toHaveBeenCalledTimes(1);
    });

    it('positive control: a genuine sync failure is still shown as Sync Failed, not as unsupported', () => {
        render(<FlowsIndex flows={[flow({ meta_sync_error: 'Meta could not sync this Flow.' })]} categories={['LEAD_GENERATION']} />);

        expect(screen.getByText('Sync Failed')).toBeInTheDocument();
        expect(screen.queryByText('Editing not supported')).not.toBeInTheDocument();
    });
});

describe('builder — a lossy import', () => {
    it('disables both Meta-upload buttons and explains why', () => {
        render(<FlowsBuilder flow={flow({ ...lossy })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByRole('button', { name: /Sync Draft to Meta/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Publish to Meta/ })).toBeDisabled();
        expect(screen.getByText(REASON)).toBeInTheDocument();
        expect(screen.getByText(/cannot be synced until support is added/i)).toBeInTheDocument();
    });

    it('does not disable Send Test — sending a Flow is not an upload over its content', () => {
        render(<FlowsBuilder flow={flow({ ...lossy })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByRole('button', { name: /Send Test/ })).not.toBeDisabled();
    });

    it('positive control: a normal draft keeps both Meta buttons enabled and shows no warning', () => {
        render(<FlowsBuilder flow={flow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByRole('button', { name: /Sync Draft to Meta/ })).not.toBeDisabled();
        expect(screen.getByRole('button', { name: /Publish to Meta/ })).not.toBeDisabled();
        expect(screen.queryByText(/cannot be synced until support is added/i)).not.toBeInTheDocument();
    });
});
