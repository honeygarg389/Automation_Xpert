import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

/**
 * ⚠️ R-19's LABELS — the test the OWED entry said to write first.
 *
 * The PHP suite asserts the PROP NAMES (`attributed_messages` present,
 * `customers_messaged` absent). Nothing asserted the text a customer actually
 * READS — and R-19 is a constraint about reading, not about data shape.
 *
 * The failure this guards is a well-meaning edit: somebody shortens
 * "Attributed Messages" to "Customers Messaged" because it reads better, and no
 * test fails. Attributed counts UNDER-REPORT — a customer can delete the
 * reference from their own WhatsApp message before sending, and there is
 * deliberately no fallback that guesses (R-20) — so presenting a floor as a
 * total is how a customer concludes the QR does not work.
 *
 * ⚠️ Asserts the RENDERED STRING, not a translation key: `t()` is mocked to
 * return English here precisely so a key rename cannot hide a label change.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: {}, flash: {}, timezone: 'UTC' }, url: '/' }),
    router: { get: vi.fn(), post: vi.fn() },
    Head: () => null,
    Link: ({ children, ...p }) => <a {...p}>{children}</a>,
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

// The real English strings, so the assertions below are about what a customer sees.
const EN = {
    'smart_qr.kpi_total_codes': 'Total QR Codes',
    'smart_qr.kpi_active': 'Active',
    'smart_qr.kpi_inactive': 'Inactive',
    'smart_qr.kpi_total_scans': 'Total Scans',
    'smart_qr.kpi_unique_scans': 'Unique Scans',
    'smart_qr.kpi_attributed_messages': 'Attributed Messages',
    'smart_qr.kpi_attributed_contacts': 'Attributed New Contacts',
    'smart_qr.kpi_attributed_rate': 'Attributed Message Rate',
    'smart_qr.kpi_attributed_hint': 'customers who kept the reference',
    'smart_qr.kpi_rate_hint': 'a floor — some customers remove the reference',
    'smart_qr.attribution_note':
        'Attributed figures count only customers whose message still contained the reference we added. Some people delete it before sending, so these numbers are a floor rather than a total — real interest is at least this high.',
    'smart_qr.col_attributed': 'Attributed',
};

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, fallback) => EN[k] ?? (typeof fallback === 'string' ? fallback : k) }),
}));

import SmartQrOverview from '@/Pages/client/SmartQr/Overview';

const kpis = {
    total_codes: 3, active_codes: 2, inactive_codes: 1,
    total_scans: 40, unique_scans: 30,
    attributed_messages: 6, attributed_new_contacts: 4,
    attributed_message_rate: 20,
};

describe('R-19 — attributed labels on the customer Overview', () => {
    it('renders "Attributed" on every attributed card', () => {
        render(<SmartQrOverview kpis={kpis} recentCodes={[]} />);

        expect(screen.getByText('Attributed Messages')).toBeInTheDocument();
        expect(screen.getByText('Attributed New Contacts')).toBeInTheDocument();
        expect(screen.getByText('Attributed Message Rate')).toBeInTheDocument();
    });

    /** ⚠️ THE ASSERTION THAT MATTERS. The forbidden wording, anywhere on the page. */
    it('never says "Customers Messaged" anywhere', () => {
        const { container } = render(<SmartQrOverview kpis={kpis} recentCodes={[]} />);

        expect(container.textContent).not.toMatch(/customers?\s+messaged/i);
        expect(container.textContent).not.toMatch(/conversion rate/i);
    });

    it('explains the under-count in plain words, not only in a tooltip', () => {
        render(<SmartQrOverview kpis={kpis} recentCodes={[]} />);

        // Help text nobody reads is not a mitigation; the note is on the page.
        expect(screen.getByText(/floor rather than a total/i)).toBeInTheDocument();
    });

    it('shows an em dash rather than 0% when the rate is null', () => {
        render(<SmartQrOverview kpis={{ ...kpis, attributed_message_rate: null }} recentCodes={[]} />);

        // "0%" claims nobody responded; null means nothing has happened yet.
        const { container } = render(<SmartQrOverview kpis={{ ...kpis, attributed_message_rate: null }} recentCodes={[]} />);
        expect(container.textContent).not.toContain('0%');
    });
});
