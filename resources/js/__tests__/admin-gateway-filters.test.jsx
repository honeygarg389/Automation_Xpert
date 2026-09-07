import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ THE GATEWAY FILTER DROPDOWNS MUST LIST EVERY GATEWAY THE APP CAN BILL WITH.
 *
 * Both admin filters were written when the set was Stripe/PayPal/Paddle and were
 * never revisited as gateways came and went. After the 2026-09-07 removal of nine
 * gateways they listed only Stripe and PayPal — so an admin could not filter for
 * Razorpay or Cashfree, two of the four LIVE gateways, and nothing failed.
 *
 * The failure mode is why this test exists: a missing <option> does not error, it
 * silently narrows what an admin can see. The filter still "works" — it just
 * cannot express half the data.
 *
 * ⚠️ Asserted against the four keeper KEYS, not a count. A count passes if
 * someone swaps one gateway for another; the keys are the actual contract with
 * the controllers, which filter on the raw value (`where('gateway', …)` in
 * Admin\PaymentController:21-23 and Admin\SubscriptionController:30-32).
 */

const KEEPERS = ['stripe', 'paypal', 'razorpay', 'cashfree'];
const REMOVED = ['paddle', 'tap', 'paystack', 'xendit', 'paymob', 'myfatoorah', 'mollie', 'square', 'mercadopago'];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { permissions: [] }, flash: {}, timezone: 'UTC' }, url: '/admin' }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
    useForm: () => ({
        data: {}, setData: vi.fn(), post: vi.fn(), put: vi.fn(), processing: false,
        errors: {}, reset: vi.fn(), clearErrors: vi.fn(),
    }),
}));

// Resolves against the real en.json, falling back to the key — same convention as
// the other page tests, so a missing key surfaces rather than silently passing.
vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (k) => {
            const en = JSON.parse(fs.readFileSync('resources/js/locales/en.json', 'utf8'));
            const parts = k.split('.');
            let node = en[parts[0]];
            for (let i = 1; i < parts.length && node != null; i++) node = node[parts[i]];
            return typeof node === 'string' ? node : k;
        },
    }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

const emptyPage = { data: [], links: [], meta: { current_page: 1, last_page: 1 } };

async function renderPayments() {
    const { default: Page } = await import('@/Pages/Admin/Payments/Index');
    render(<Page payments={emptyPage} filters={{}} />);
}

async function renderSubscriptions() {
    const { default: Page } = await import('@/Pages/Admin/Subscriptions/Index');
    render(<Page subscriptions={emptyPage} filters={{}} plans={[]} />);
}

function gatewayOptionValues() {
    const select = document.querySelector('select[name="gateway"]');
    expect(select, 'no <select name="gateway"> on the page').not.toBeNull();
    return Array.from(select.querySelectorAll('option')).map((o) => o.value);
}

describe('admin gateway filter dropdowns', () => {
    it.each([
        ['Payments', renderPayments],
        ['Subscriptions', renderSubscriptions],
    ])('%s lists all four keeper gateways', async (_name, mount) => {
        await mount();
        const values = gatewayOptionValues();

        for (const key of KEEPERS) {
            expect(values, `"${key}" is missing — admins cannot filter by it`).toContain(key);
        }
    });

    it.each([
        ['Payments', renderPayments],
        ['Subscriptions', renderSubscriptions],
    ])('%s offers no removed gateway', async (_name, mount) => {
        await mount();
        const values = gatewayOptionValues();

        for (const key of REMOVED) {
            expect(values, `"${key}" was removed but is still offered — it would always return zero rows`).not.toContain(key);
        }
    });

    it('Payments shows the brand names, not raw keys', async () => {
        await renderPayments();
        for (const label of ['Stripe', 'PayPal', 'Razorpay', 'Cashfree']) {
            expect(screen.getByRole('option', { name: label })).toBeTruthy();
        }
    });

    /** The empty "all gateways" option and Subscriptions' "manual" must survive. */
    it('Subscriptions keeps the all-gateways and manual options', async () => {
        await renderSubscriptions();
        const values = gatewayOptionValues();
        expect(values).toContain('');
        expect(values).toContain('manual');
    });
});
