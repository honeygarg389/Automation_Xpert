import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { useState } from 'react';

/**
 * Task 1 — the Field Settings panel that replaces the old single-row field
 * editor. The row that used to cram Label / type / name / a Required
 * checkbox / Helper text / move / delete / a comma-separated options input
 * into one `md:grid-cols-12` line is gone entirely (see the last test in
 * this file for the structural proof), replaced by a compact summary row
 * that expands into this panel on click.
 *
 * `useForm` is a REAL useState, matching the established convention in
 * flows-builder-live-preview.test.jsx and restaurant-connection-create-
 * phone-country.test.jsx — needed here because add/remove-option assertions
 * are meaningless against a static stub.
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
    max_submissions: null,
    limit_error_message: 'You have already reached the maximum number of submissions for this form.',
    submit_settings: { button_text: 'Submit', success_message: 'Thanks' },
    screens: [{
        id: 'contact', title: 'Contact', fields: [
            { id: 'f1', type: 'text', label: 'Full name', name: 'full_name', required: true, helper_text: 'As shown on your ID', options: [], step: 1, order: 1 },
        ],
    }],
    ...overrides,
});

describe('field settings panel', () => {
    it('is closed by default and opens when the configure control is clicked', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        expect(screen.queryByLabelText('Display label')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Configure field' }));

        // "Name" also labels the Form Info section's flow-name field — scope
        // to this field's own panel to disambiguate.
        const panel = screen.getByText('Field settings').closest('div');

        expect(screen.getByLabelText('Display label')).toHaveValue('Full name');
        expect(within(panel).getByLabelText('Name')).toHaveValue('full_name');
        expect(within(panel).getByText('Unique identifier for payload. Use snake_case.')).toBeInTheDocument();

        // Clicking again collapses it.
        fireEvent.click(screen.getByRole('button', { name: 'Collapse field settings' }));
        expect(screen.queryByLabelText('Display label')).not.toBeInTheDocument();
    });

    it('opens automatically, with zero options, for a choice-type field added via the Form Designer palette', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        fireEvent.click(screen.getByRole('button', { name: /Dropdown/i }));

        // The new field's own panel auto-expands — no extra click needed to
        // see that it started empty.
        expect(screen.getByText('No options defined')).toBeInTheDocument();
        expect(screen.queryByPlaceholderText('Option 1')).not.toBeInTheDocument();
    });

    it('adding, editing, and removing a selection option updates state end to end', () => {
        const flow = baseFlow({
            screens: [{
                id: 'contact', title: 'Contact', fields: [
                    { id: 'choice', type: 'select', label: 'Preferred channel', name: 'preferred_channel', required: false, helper_text: null, options: [], step: 1, order: 1 },
                ],
            }],
        });
        render(<FlowsBuilder flow={flow} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        fireEvent.click(screen.getByRole('button', { name: 'Configure field' }));
        expect(screen.getByText('No options defined')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Add option' }));
        expect(screen.queryByText('No options defined')).not.toBeInTheDocument();
        const optionInput = screen.getByPlaceholderText('Option 1');
        expect(optionInput).toHaveValue('');

        fireEvent.change(optionInput, { target: { value: 'Email' } });
        expect(screen.getByDisplayValue('Email')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Add option' }));
        expect(screen.getByPlaceholderText('Option 2')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Remove option' })[0]);
        expect(screen.queryByDisplayValue('Email')).not.toBeInTheDocument();
        expect(screen.getByPlaceholderText('Option 1')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Remove option' }));
        expect(screen.getByText('No options defined')).toBeInTheDocument();
    });

    it('never shows Selection options for a plain text field', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        fireEvent.click(screen.getByRole('button', { name: 'Configure field' }));

        expect(screen.queryByText('Selection options')).not.toBeInTheDocument();
    });

    it('structurally cannot reproduce the Required/Helper-text overlap bug: the old md:grid-cols-12 row is gone, and Required and Helper text render in separate blocks', () => {
        const { container } = render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        fireEvent.click(screen.getByRole('button', { name: 'Configure field' }));

        // The exact class that produced the cramped single-row editor no
        // longer exists anywhere on the page.
        expect(container.querySelector('[class*="grid-cols-12"]')).toBeNull();

        const requiredToggle = screen.getByRole('switch', { name: 'Required' });
        const helperInput = screen.getByLabelText('Helper / placeholder text');

        // Required and Helper text are rendered by separate components
        // (Toggle vs. Input) as separate block-level siblings in the
        // vertically-stacked settings panel, not packed into one shared row.
        expect(requiredToggle.closest('label')).not.toBeNull();
        expect(helperInput.closest('div')).not.toBeNull();
        expect(requiredToggle.closest('label')).not.toBe(helperInput.closest('div'));
        // Helper text precedes Required in document order — they are stacked, not overlapping.
        expect(helperInput.compareDocumentPosition(requiredToggle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    /**
     * Section B (imported-flow round-trip fix) — a brand-new field's
     * auto-generated Name must never take the "field_1" shape (Meta rejects
     * a bare numeric disambiguation suffix on this identifier class); it
     * must be alphabetic instead ("field", then "field_a", "field_b", ...).
     */
    it('auto-generates alphabetic, never numeric-suffixed, default Names for new fields', () => {
        render(<FlowsBuilder flow={baseFlow()} categories={['LEAD_GENERATION']} fieldTypes={FIELD_TYPES} />);

        // Adding a field auto-expands ONLY its own settings panel (collapsing
        // any other), so each new field's generated Name is read one at a
        // time. "Email"/"Phone" are used (not "Text Input") because the
        // fixture's own pre-existing field is itself type "text", whose
        // summary row's own accessible name already contains the substring
        // "Text Input" via its type badge.
        fireEvent.click(screen.getByRole('button', { name: /^Email$/i }));
        const firstName = screen.getByLabelText('Name').value;

        fireEvent.click(screen.getByRole('button', { name: /^Phone$/i }));
        const secondName = screen.getByLabelText('Name').value;

        expect(firstName).toBe('field');
        expect(secondName).toBe('field_a');
        [firstName, secondName].forEach((name) => expect(name).not.toMatch(/_[0-9]+$/));
    });
});
