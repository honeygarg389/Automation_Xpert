import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
}));

import PlanTable from '@/Pages/Admin/Plans/PlanTable';

const plan = {
    id: 1,
    name: 'Starter',
    description: 'A test plan',
    monthly_price_cents: 1000,
    yearly_price_cents: 10000,
    currency_code: 'USD',
    features: [],
    limits: {},
    enabled: true,
    popular: false,
    featured: false,
};

describe('Plans table row actions portal', () => {
    it('opens outside both overflow clipping ancestors and still closes with Escape', async () => {
        render(<PlanTable plans={[plan]} onEdit={vi.fn()} onDuplicate={vi.fn()} onToggleEnabled={vi.fn()} onDelete={vi.fn()} onReorder={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'admin.plan_actions' }));

        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).toBeInTheDocument());
        const menu = document.body.querySelector('[data-dropdown-content]');
        const scroller = screen.getByRole('table').parentElement;
        const card = scroller.parentElement;
        expect(scroller).toHaveClass('overflow-x-auto');
        expect(card).toHaveClass('overflow-hidden');
        expect(scroller.contains(menu)).toBe(false);
        expect(card.contains(menu)).toBe(false);

        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());
    });
});
