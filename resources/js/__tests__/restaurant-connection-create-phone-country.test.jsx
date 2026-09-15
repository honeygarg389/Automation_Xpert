import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from 'react';

/**
 * ⚠️ WHAT THIS FILE PINS — the "New Petpooja Connection" form's Default
 * phone country field on a genuinely FRESH mount, no user interaction, no
 * preselected outlet.
 *
 * A visual review found the field visibly showing "India (+91)" selected on
 * a fresh screen. Reading Create.jsx's source shows the initial `useForm`
 * state is already `default_phone_country: ''`, and Select.jsx already
 * renders an unselected placeholder option first — so a prop-shape or
 * backend-null check alone cannot catch this: it can only be caught by
 * actually mounting the real component and reading the rendered DOM,
 * exactly as this file does.
 *
 * ⚠️ `useForm` below is a REAL `useState`, not a static stub returning
 * `data: initial` — the same convention smartqr-batch-add-codes.test.jsx
 * uses. A static stub can't distinguish "the component's initial state is
 * correct" from "the component's initial state is wrong but happens to be
 * rendered once" in quite the same way; using real state additionally lets
 * this file simulate the admin actually choosing India further down and
 * proving THAT persists, rather than only proving the empty case renders.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { permissions: [] }, flash: {} }, url: '/admin/restaurant/connections/create' }),
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (initial) => {
        const [data, set] = useState(initial);
        return {
            data,
            setData: (k, v) => {
                if (typeof k === 'function') {
                    set((prev) => k(prev));
                } else {
                    set((prev) => ({ ...prev, [k]: v }));
                }
            },
            post: vi.fn(),
            transform: vi.fn(),
            processing: false,
            errors: {},
        };
    },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import Create from '@/Pages/Admin/Restaurant/Connections/Create';

const PHONE_COUNTRIES = [
    { code: 'IN', name: 'India', calling_code: '+91', label: 'India (+91)', example: '9123456789' },
    { code: 'US', name: 'United States', calling_code: '+1', label: 'United States (+1)', example: '9123456789' },
    { code: 'GB', name: 'United Kingdom', calling_code: '+44', label: 'United Kingdom (+44)', example: '7123456789' },
];

const renderPage = () =>
    render(
        <Create
            workspaces={[{ id: 1, name: 'Test Workspace', client_name: null }]}
            outlets={[]}
            preselect={{ workspace_id: null, outlet_id: null }}
            phoneCountries={PHONE_COUNTRIES}
        />
    );

describe('New Petpooja Connection — Default phone country', () => {
    it('shows no country selected on a fresh mount, even though India is listed first', () => {
        renderPage();

        const select = screen.getByLabelText('Default phone country (optional)');
        expect(select.value).toBe('');

        // The DOM's actually-selected <option> must be the empty placeholder,
        // not India merely because it sorts first in the options list — this
        // is the exact distinction a prop/state-shape check cannot make.
        const selectedOption = Array.from(select.options).find((o) => o.selected);
        expect(selectedOption.value).toBe('');
        expect(selectedOption.textContent).not.toMatch(/india|\+91/i);
    });

    it('never renders "India" or "+91" anywhere in the field before a user interacts with it', () => {
        renderPage();

        const select = screen.getByLabelText('Default phone country (optional)');
        // The visible (collapsed) state of a <select> is its selected option's
        // text — read via .value/selectedIndex rather than full innerHTML,
        // since the closed OPTIONS LIST legitimately contains "India (+91)"
        // as the first choice and must not be mistaken for it being shown.
        const visibleText = select.options[select.selectedIndex].textContent;
        expect(visibleText).toBe('No country selected');
    });

    it('defends against a class of bug this component cannot see: the <select> opts out of browser autofill', () => {
        renderPage();

        const select = screen.getByLabelText('Default phone country (optional)');
        // Chrome-class browsers can silently paint a DIFFERENT option as
        // selected in the live DOM, independent of React's controlled value,
        // when a <select> sits in a form with an "Address" field and reads as
        // an address form. jsdom cannot reproduce that behavior, so this
        // pins the actual opt-out attribute rather than the symptom.
        expect(select).toHaveAttribute('autocomplete', 'off');
    });

    it('explicitly selecting India updates the field and nothing resets it back to blank', () => {
        renderPage();

        const select = screen.getByLabelText('Default phone country (optional)');
        fireEvent.change(select, { target: { value: 'IN' } });

        expect(select.value).toBe('IN');
        expect(select.options[select.selectedIndex].textContent).toBe('India (+91)');
    });

    it('switching the connection environment does not touch the phone country field', () => {
        renderPage();

        const select = screen.getByLabelText('Default phone country (optional)');
        fireEvent.change(select, { target: { value: 'GB' } });
        fireEvent.click(screen.getByLabelText('Live Petpooja'));

        expect(select.value).toBe('GB');
    });
});
