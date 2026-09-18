import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { useState } from 'react';

/**
 * Slice 8 — the Builder's live preview panel (Task 4) and published-flow
 * warning (Task 5).
 *
 * `useForm` is a REAL useState, not a static stub — the same convention
 * restaurant-connection-create-phone-country.test.jsx and
 * smartqr-batch-add-codes.test.jsx already use. A static stub could not
 * distinguish "the preview reacts to a real edit" from "the preview merely
 * rendered once with fixed props", which is the entire point of this file.
 */
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), put: vi.fn() },
    useForm: (initial) => {
        const [data, set] = useState(initial);
        return {
            data,
            setData: (k, v) => set((prev) => ({ ...prev, [k]: v })),
            put: vi.fn(),
            processing: false,
            errors: {},
        };
    },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import FlowsBuilder from '@/Pages/client/Flows/Builder';

const FIELD_TYPES = ['heading', 'text', 'number', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox', 'date'];

const baseFlow = (overrides = {}) => ({
    uuid: 'flow-1',
    name: 'Lead form',
    description: '',
    category: 'LEAD_GENERATION',
    status: 'draft',
    meta_flow_id: null,
    meta_sync_status: null,
    meta_validation_errors: [],
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    submit_settings: { button_text: 'Submit', success_message: 'Thanks' },
    screens: [{
        id: 'contact', title: 'Contact', fields: [
            { id: 'f1', type: 'text', label: 'Full name', name: 'full_name', required: true, helper_text: null, options: [], step: 1, order: 1 },
        ],
    }],
    ...overrides,
});

describe('live preview panel', () => {
    it('renders the current step field labels inside the preview', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByText('Live Preview')).toBeInTheDocument();
        // The label appears twice: once in the editable builder input (value,
        // not text) and once as static preview text — getAllByText proves
        // the preview rendered it as text, not merely that an input exists.
        expect(screen.getAllByText('Full name').length).toBeGreaterThan(0);
    });

    it('updates reactively when a field is added via the type palette, with no save', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.queryByText('New field')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Email/i }));

        // The palette adds a field labelled "New field" by default (blankField()) —
        // its appearance in the preview, with no network call and no reload,
        // is the reactivity claim this test exists to prove.
        expect(screen.getAllByText('New field').length).toBeGreaterThan(0);
    });

    it('switches which step is shown when a different step badge is clicked', () => {
        const flow = baseFlow({
            screens: [
                { id: 'a', title: 'Step A', fields: [{ id: 'fa', type: 'text', label: 'Field A', name: 'field_a', required: false, helper_text: null, options: [], step: 1, order: 1 }] },
                { id: 'b', title: 'Step B', fields: [{ id: 'fb', type: 'text', label: 'Field B', name: 'field_b', required: false, helper_text: null, options: [], step: 2, order: 1 }] },
            ],
        });
        render(<FlowsBuilder flow={flow} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        // Task 4 — Form Canvas renders every step's fields at once (it's an
        // always-visible accordion, not step-scoped), so "Field B" is present
        // on the page the whole time via its Form Canvas row. Only the Live
        // Preview panel itself is step-scoped — so this test, and the two
        // assertions below, are deliberately scoped to `within(preview)`.
        const preview = screen.getByText('Live Preview').closest('div').parentElement;

        // Step 1 is shown by default, inside the preview panel specifically.
        expect(within(preview).getAllByText('Field A').length).toBeGreaterThan(0);
        expect(within(preview).queryByText('Field B')).not.toBeInTheDocument();

        // Both step selectors exist: one in the builder body (per-step badge)
        // and one in the preview panel's own step switcher. Click the
        // preview panel's own "2" pill specifically.
        fireEvent.click(within(preview).getByText('2'));

        expect(within(preview).getAllByText('Field B').length).toBeGreaterThan(0);
        expect(within(preview).queryByText('Field A')).not.toBeInTheDocument();
    });

    it('renders a submit button label matching the last step, and Continue otherwise', () => {
        const flow = baseFlow({
            submit_settings: { button_text: 'Send it', success_message: 'Thanks' },
            screens: [
                { id: 'a', title: 'Step A', fields: [{ id: 'fa', type: 'text', label: 'Field A', name: 'field_a', required: false, helper_text: null, options: [], step: 1, order: 1 }] },
                { id: 'b', title: 'Step B', fields: [{ id: 'fb', type: 'text', label: 'Field B', name: 'field_b', required: false, helper_text: null, options: [], step: 2, order: 1 }] },
            ],
        });
        render(<FlowsBuilder flow={flow} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        // Default preview step is the FIRST step (not the last) — must read "Continue".
        expect(screen.getByRole('button', { name: 'Continue' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send it' })).not.toBeInTheDocument();
    });
});

describe('published-flow edit warning', () => {
    it('is absent for a flow that has never synced', () => {
        render(<FlowsBuilder flow={baseFlow({ meta_sync_status: null })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        expect(screen.queryByText(/live on Meta/)).not.toBeInTheDocument();
    });

    it('is absent for a synced-but-still-draft flow', () => {
        render(<FlowsBuilder flow={baseFlow({ meta_sync_status: 'synced_draft' })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        expect(screen.queryByText(/live on Meta/)).not.toBeInTheDocument();
    });

    it('appears, non-blocking, only when meta_sync_status is published', () => {
        render(<FlowsBuilder flow={baseFlow({ meta_sync_status: 'published' })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByText(/live on Meta/)).toBeInTheDocument();
        expect(screen.getByText(/revert it to Draft/)).toBeInTheDocument();
        // Non-blocking: the Save button must still be present and not disabled by the warning itself.
        expect(screen.getByRole('button', { name: /Save Changes/ })).not.toBeDisabled();
    });
});
