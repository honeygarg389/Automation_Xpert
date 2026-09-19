import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react';
import { useState } from 'react';

/**
 * The Flow builder's "Send Test" action — a dedicated header button (not a
 * 7th ⋮-menu item on the dashboard card) that opens a modal for sending the
 * real Flow to one WhatsApp number via WhatsappFlowController::testSend().
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
    name: 'Order Form',
    description: '',
    category: 'LEAD_GENERATION',
    status: 'draft',
    meta_flow_id: 'meta-flow-1',
    meta_sync_status: 'synced_draft',
    meta_validation_errors: [],
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    max_submissions: null,
    limit_error_message: 'You have already reached the maximum number of submissions for this form.',
    submit_settings: { button_text: 'Submit', success_message: 'Thanks' },
    screens: [{
        id: 'contact', title: 'Contact', fields: [
            { id: 'f1', type: 'text', label: 'Full name', name: 'full_name', required: true, helper_text: null, options: [], step: 1, order: 1 },
        ],
    }],
    ...overrides,
});

const openModal = () => fireEvent.click(screen.getByRole('button', { name: /Send Test/ }));
const phoneInput = () => screen.getByPlaceholderText('e.g. 919690309316');
const sendNowButton = () => screen.getByRole('button', { name: 'Send Now' });

describe('Send Test button', () => {
    it('is disabled until the flow is synced to Meta (has a meta_flow_id)', () => {
        render(<FlowsBuilder flow={baseFlow({ meta_flow_id: null })} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByRole('button', { name: /Send Test/ })).toBeDisabled();
    });

    it('is enabled once the flow is synced', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.getByRole('button', { name: /Send Test/ })).not.toBeDisabled();
    });
});

describe('Send Test modal', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders the flow name and Flow ID in a highlighted box', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        openModal();

        const dialog = within(screen.getByRole('dialog'));
        expect(dialog.getByText('Order Form')).toBeInTheDocument();
        expect(dialog.getByText('Flow ID: meta-flow-1')).toBeInTheDocument();
    });

    it('Send Now is disabled until the phone number has a plausible value, and enables once it does', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        openModal();

        expect(sendNowButton()).toBeDisabled();

        fireEvent.change(phoneInput(), { target: { value: '123' } });
        expect(sendNowButton()).toBeDisabled();

        fireEvent.change(phoneInput(), { target: { value: '919690309316' } });
        expect(sendNowButton()).not.toBeDisabled();
    });

    it('calls the test-send endpoint with the phone number and shows the success message on success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ message: 'Test message sent to +919690309316.' }),
        }));
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        openModal();
        fireEvent.change(phoneInput(), { target: { value: '919690309316' } });
        fireEvent.click(sendNowButton());

        await waitFor(() => expect(screen.getByText('Test message sent to +919690309316.')).toBeInTheDocument());

        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('client.flows.test-send'),
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ phone_number: '919690309316' }),
            })
        );
    });

    it('shows the server error message on failure, without closing the modal', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'Connect an active WhatsApp Business Account before sending a test message.' }),
        }));
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);
        openModal();
        fireEvent.change(phoneInput(), { target: { value: '919690309316' } });
        fireEvent.click(sendNowButton());

        await waitFor(() => expect(screen.getByText('Connect an active WhatsApp Business Account before sending a test message.')).toBeInTheDocument());
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
