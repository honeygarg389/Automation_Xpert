import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';

/**
 * Requirements 1, 4, 6: the Default phone country control must
 *
 *   1. show a clear selected country/calling code before import
 *   4. never silently assume +91 — no country is preselected by default
 *   6. explain, in words, that local numbers use the selected country and
 *      +numbers keep their own
 *
 * ⚠️ Asserts REAL English copy read from en.json (the same convention as
 * smartqr-labels.test.jsx / smartqr-batch-add-codes.test.jsx), not translation
 * keys — a key rename that silently changes what's on screen must fail this
 * test, not pass it.
 */

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (k, o) => {
            const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
            const parts = k.split('.');
            let node = en[parts[0]];
            for (let i = 1; i < parts.length && node != null; i++) node = node[parts[i]];
            if (typeof node !== 'string') return k;
            return o ? node.replace(/\{\{(\w+)\}\}/g, (_, n) => o[n] ?? '') : node;
        },
    }),
}));

import PhoneCountrySelect from '@/Components/PhoneCountrySelect';

const COUNTRIES = [
    { code: 'IN', name: 'India', calling_code: '+91', label: 'India (+91)', example: '9123456789' },
    { code: 'US', name: 'United States', calling_code: '+1', label: 'United States (+1)', example: '9123456789' },
];

describe('PhoneCountrySelect', () => {
    it('with no value selected, shows an unselected state rather than a silent default', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="" onChange={() => {}} />);

        // Requirement 4: nothing on screen may read as "+91" or any specific
        // country until the user has actually chosen one.
        expect(screen.getByTestId('phone-country-summary').textContent).not.toMatch(/\+\d/);
        expect(screen.getByTestId('phone-country-summary').textContent.toLowerCase()).toContain('no default');
    });

    it('once a country is chosen, the selected country and calling code are shown in plain text', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="IN" onChange={() => {}} />);

        const summary = screen.getByTestId('phone-country-summary').textContent;
        expect(summary).toContain('India');
        expect(summary).toContain('+91');
    });

    it('choosing a country calls onChange with its code', () => {
        const onChange = vi.fn();
        render(<PhoneCountrySelect countries={COUNTRIES} value="" onChange={onChange} />);

        fireEvent.change(screen.getByRole('combobox'), { target: { value: 'US' } });

        expect(onChange).toHaveBeenCalledWith('US');
    });

    it('explains that local numbers use the selected country, with a concrete example', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="IN" onChange={() => {}} />);

        const text = screen.getByText(/9123456789/).textContent;
        expect(text).toContain('+91');
    });

    it('explains that a + number keeps its own country code, independent of the selection', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="IN" onChange={() => {}} />);

        expect(screen.getByText(/keep their own country code/i)).toBeTruthy();
    });

    it('with no country selected, warns that local numbers cannot be imported yet', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="" onChange={() => {}} />);

        expect(screen.getByText(/will be skipped until you select a default phone country/i)).toBeTruthy();
    });

    it('states the final stored format is always E.164, regardless of the selection', () => {
        render(<PhoneCountrySelect countries={COUNTRIES} value="IN" onChange={() => {}} />);

        expect(screen.getByText(/E\.164/)).toBeTruthy();
    });
});
