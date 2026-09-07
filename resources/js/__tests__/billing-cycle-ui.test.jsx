import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';

/**
 * ⚠️ THE CYCLE SELECTOR MUST OFFER ONLY WHAT THE PLAN IS SOLD ON.
 *
 * A cycle with no configured price sends the customer to a checkout that
 * refuses — the gateway returns "Plan has no price for this billing cycle"
 * AFTER they have chosen a plan and clicked pay. The failure is invisible at
 * design time because the button renders perfectly well.
 *
 * Also pins the savings badge, which is now COMPUTED. It used to be a hardcoded
 * "Save 15%" — a claim that was true only if whoever configured the prices made
 * it true. With four cycles that would have been three more unverifiable claims.
 */

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { permissions: [] }, flash: {} }, url: '/app/pricing' }),
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
    // ⚠️ The mock must RETURN THE INITIAL VALUES it is handed. A mock that
    // returns `data: {}` makes every component read undefined for its own
    // defaults — here that left the selected plan null, so no badge rendered and
    // the assertions failed for a reason that had nothing to do with the code.
    useForm: (initial = {}) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));

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

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

const plan = (overrides = {}) => ({
    id: 1,
    name: 'Pro',
    description: 'desc',
    features: [],
    limits: {},
    trial_days: 0,
    is_free: false,
    popular: false,
    available_cycles: ['month', 'quarter', 'year'],
    prices_cents: { month: 1000, quarter: 2700, half_year: null, year: 9600 },
    prices_display: { month: '$10.00', quarter: '$27.00', half_year: null, year: '$96.00' },
    ...overrides,
});

async function renderPricing(plans) {
    const { default: Pricing } = await import('@/Pages/client/Pricing');
    render(<Pricing plans={plans} gateways={[]} flash={{}} is_authenticated={false} />);
}

describe('pricing cycle selector', () => {
    it('offers only the cycles at least one plan is sold on', async () => {
        await renderPricing([plan()]);

        for (const label of ['Monthly', 'Quarterly', 'Yearly']) {
            expect(screen.getByRole('button', { name: new RegExp(label) }),
                `"${label}" should be offered`).toBeTruthy();
        }
        // half_year has a null price on every plan — offering it would send the
        // customer to a checkout that refuses.
        expect(screen.queryByRole('button', { name: /Half-Yearly/ })).toBeNull();
    });

    it('always offers monthly even when nothing else is configured', async () => {
        await renderPricing([plan({ available_cycles: ['month'], prices_cents: { month: 1000 } })]);

        expect(screen.getByRole('button', { name: /Monthly/ })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Quarterly/ })).toBeNull();
    });

    /** 2700 vs 3x1000 = 10% off; 9600 vs 12x1000 = 20% off. */
    it('computes the savings badge from real prices', async () => {
        await renderPricing([plan()]);

        expect(screen.getByRole('button', { name: /Quarterly.*10%/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /Yearly.*20%/ })).toBeTruthy();
    });

    /**
     * ⚠️ A cycle priced at or above the monthly equivalent gets NO badge, rather
     * than a badge claiming a saving that does not exist.
     */
    it('shows no badge when a cycle is not actually cheaper', async () => {
        await renderPricing([plan({
            available_cycles: ['month', 'quarter'],
            prices_cents: { month: 1000, quarter: 3000 },
            prices_display: { month: '$10.00', quarter: '$30.00' },
        })]);

        const quarterly = screen.getByRole('button', { name: /Quarterly/ });
        expect(quarterly.textContent).not.toMatch(/%/);
    });
});

describe('subscription change-plan modal', () => {
    const subscription = {
        plan: { id: 1, name: 'Pro', slug: 'pro', trial_days: 0 },
        billing_cycle: 'month',
        status: 'active',
        renews_at: null,
        ends_at: null,
        trial_ends_at: null,
        gateway: 'stripe',
        managed_by_admin: false,
    };

    async function openModal(planOverrides = {}) {
        const { default: Show } = await import('@/Pages/client/Subscription/Show');
        render(
            <Show
                subscription={subscription}
                canCancel
                canUpgrade
                plans={[plan(planOverrides)]}
                transactions={[]}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: /Change Plan/ }));
    }

    const cycleButton = (label) => {
        const btns = screen.getAllByRole('button').filter((b) => b.textContent.includes(label));
        expect(btns.length, `no cycle button for ${label}`).toBeGreaterThan(0);
        return btns[0];
    };

    it('offers only the cycles the selected plan is sold on', async () => {
        // monthly + yearly priced; quarter and half_year are not sold.
        await openModal({
            available_cycles: ['month', 'year'],
            prices_cents: { month: 1000, quarter: null, half_year: null, year: 9600 },
        });

        expect(cycleButton('Monthly')).toBeTruthy();
        expect(cycleButton('Annual')).toBeTruthy();

        const labels = screen.getAllByRole('button').map(b => b.textContent).join('|');
        expect(labels, 'Quarterly is selectable but the plan has no quarterly price').not.toMatch(/Quarterly/);
        expect(labels, 'Half-Yearly is selectable but the plan has no half-yearly price').not.toMatch(/Half-Yearly/);
    });

    it('offers all four when the plan is sold on all four', async () => {
        await openModal({
            available_cycles: ['month', 'quarter', 'half_year', 'year'],
            prices_cents: { month: 1000, quarter: 2700, half_year: 5100, year: 9600 },
        });

        for (const label of ['Monthly', 'Quarterly', 'Half-Yearly', 'Annual']) {
            expect(cycleButton(label), `${label} should be offered`).toBeTruthy();
        }
    });

    /**
     * ⚠️ THE MISMATCH CASE. A customer on monthly opening a plan sold only
     * yearly: the stored cycle has no button. It must not crash, must not offer
     * the unpriced cycle, and must leave nothing pre-selected so the customer
     * has to make a valid choice.
     */
    it('survives the current cycle not being offered by the chosen plan', async () => {
        await openModal({
            available_cycles: ['year'],
            prices_cents: { month: null, quarter: null, half_year: null, year: 9600 },
        });

        const labels = screen.getAllByRole('button').map(b => b.textContent).join('|');
        expect(labels).toMatch(/Annual/);
        expect(labels, 'monthly is offered despite having no price').not.toMatch(/Monthly/);

        // Nothing is highlighted, because the stored 'month' has no button.
        const selected = screen.getAllByRole('button').filter(b => b.className.includes('border-brand-600'));
        expect(selected).toHaveLength(0);
    });

    /**
     * ⚠️ THE POINT OF THIS TEST. The yearly label used to be the literal string
     * "Annual (Save ~17%)" in all 17 locale files — a percentage that was
     * asserted, never computed, and stayed 17% no matter what the plan cost.
     *
     * This plan is priced so the real yearly saving is 20%, not 17%. If the
     * hardcoded copy ever comes back, the 17% shows and this fails.
     */
    it('computes the yearly saving instead of claiming a fixed 17%', async () => {
        // 1000/mo vs 9600/yr => 12000 - 9600 = 20% off
        await openModal();

        const yearly = cycleButton('Annual');
        expect(yearly.textContent).toMatch(/20%/);
        expect(yearly.textContent).not.toMatch(/17%/);
    });

    it('computes quarterly and half-yearly the same way', async () => {
        await openModal({
            // available_cycles must agree with prices_cents — the modal gates on
            // the former, so a price without its cycle listed renders no button.
            available_cycles: ['month', 'quarter', 'half_year', 'year'],
            prices_cents: { month: 1000, quarter: 2700, half_year: 5100, year: 9600 },
        });

        expect(cycleButton('Quarterly').textContent).toMatch(/10%/);  // 2700 vs 3000
        expect(cycleButton('Half-Yearly').textContent).toMatch(/15%/); // 5100 vs 6000
    });

    /** Monthly is the baseline — it can never show a saving against itself. */
    it('never badges the monthly cycle', async () => {
        await openModal();

        expect(cycleButton('Monthly').textContent).not.toMatch(/%/);
    });

    /**
     * ⚠️ No badge rather than a false one — the same rule the pricing page
     * follows. A yearly price at or above 12x monthly is not a saving.
     */
    it('shows no badge when a cycle is not cheaper than monthly', async () => {
        await openModal({ prices_cents: { month: 1000, quarter: null, half_year: null, year: 12000 } });

        expect(cycleButton('Annual').textContent).not.toMatch(/%/);
    });
});

describe('admin plan form', () => {
    async function renderForm(data = {}) {
        const { default: PlanForm } = await import('@/Pages/Admin/Plans/PlanForm');
        render(
            <PlanForm
                data={{ name: '', slug: '', currency_code: 'USD', monthly_price_cents: 1000, features: [], limits: {}, ...data }}
                setData={vi.fn()}
                errors={{}}
                processing={false}
                onSubmit={vi.fn()}
                onCancel={vi.fn()}
                isEdit={false}
                currencies={[{ code: 'USD', name: 'US Dollar' }]}
            />
        );
    }

    it('offers an enable toggle for all three optional cycles', async () => {
        await renderForm();

        for (const label of ['Enable Quarterly Pricing', 'Enable Half-Yearly Pricing', 'Enable Yearly Pricing']) {
            expect(screen.getByText(label), `missing toggle: ${label}`).toBeTruthy();
        }
    });

    it('shows a price field only for cycles that are enabled', async () => {
        await renderForm({ quarterly_price_cents: 2700 });

        // ⚠️ getByText, not getByLabelText: the shared Input renders a <label>
        // with no htmlFor, so testing-library cannot associate it with the input.
        expect(screen.getByText(/Quarterly Price \(cents\)/)).toBeTruthy();
        expect(screen.queryByText(/Half-Yearly Price \(cents\)/)).toBeNull();
    });

    it('renders a Stripe Price ID field for every cycle', async () => {
        await renderForm();

        for (const label of ['Monthly Price ID', 'Quarterly Price ID', 'Half-Yearly Price ID', 'Yearly Price ID']) {
            // ⚠️ EXACT match, not a regex: /Yearly Price ID/ also matches
            // "Half-Yearly Price ID", so a regex would pass while one of the two
            // fields was missing.
            expect(screen.getByText(label, { exact: true }), `missing: ${label}`).toBeTruthy();
        }
    });
});
