import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';

/**
 * Task 1 (Delete in the ⋮ menu) and Task 2 (the menu's clipping fix) for the
 * WhatsApp Flows card grid.
 */
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { flash: {} } }),
    router: { post: vi.fn(), delete: vi.fn() },
    Head: () => null,
    Link: ({ href, children, ...props }) => <a href={href} {...props}>{children}</a>,
}));
vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => (o?.word ? `${k}:${o.word}` : k) }),
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/EmptyState', () => ({ default: () => null }));

import { router } from '@inertiajs/react';
import FlowsIndex from '@/Pages/client/Flows/Index';

const flow = (overrides = {}) => ({
    uuid: 'flow-1',
    name: 'Lead capture',
    description: 'A description',
    category: 'LEAD_GENERATION',
    status: 'draft',
    screens: [{
        id: 'contact', title: 'Contact', fields: [
            { id: 'f1', type: 'email', label: 'Email address', name: 'email', required: true, helper_text: null, options: [], step: 1, order: 1 },
        ],
    }],
    submit_settings: { button_text: 'Go', success_message: 'Thanks!' },
    meta_flow_id: null,
    meta_sync_status: null,
    meta_validation_errors: [],
    meta_sync_error: null,
    field_count: 1,
    step_count: 1,
    submissions_count: 3,
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-02T00:00:00.000Z',
    web_form_enabled: false,
    public_slug: null,
    recaptcha_enabled: true,
    ...overrides,
});

const openMenu = () => fireEvent.click(screen.getByLabelText('Actions for Lead capture'));

describe('the card ⋮ menu — Delete', () => {
    it('lists Delete last, visually separated and styled as destructive', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = screen.getByTestId('card-actions-menu');
        const itemTexts = Array.from(menu.querySelectorAll('button, a')).map((el) => el.textContent.trim());

        expect(itemTexts[itemTexts.length - 1]).toBe('Delete');
        const deleteButton = within(menu).getByText('Delete').closest('button');
        expect(deleteButton.className).toMatch(/text-red-600/);
        // A divider sits directly before it — same visual pattern as every
        // other destructive item in this codebase's menus.
        expect(deleteButton.previousElementSibling.className).toMatch(/border-t/);
    });

    it('does not delete on a single click — it opens the typed confirmation dialog first', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        fireEvent.click(within(screen.getByTestId('card-actions-menu')).getByText('Delete'));

        expect(router.delete).not.toHaveBeenCalled();
        expect(screen.getByText('Delete WhatsApp Flow?')).toBeInTheDocument();
        expect(within(screen.getByRole('dialog')).getByText(/Lead capture/)).toBeInTheDocument();
    });

    it('only calls the existing destroy() route once the confirmation word is typed and confirmed', () => {
        render(<FlowsIndex flows={[flow({ uuid: 'flow-42' })]} categories={['LEAD_GENERATION']} />);
        openMenu();
        fireEvent.click(within(screen.getByTestId('card-actions-menu')).getByText('Delete'));

        const confirmButton = screen.getByText('common.delete').closest('button');
        const input = screen.getByLabelText('common.type_to_confirm:DELETE');

        fireEvent.change(input, { target: { value: 'delete' } });
        expect(confirmButton).toBeDisabled();
        expect(router.delete).not.toHaveBeenCalled();

        fireEvent.change(input, { target: { value: 'DELETE' } });
        expect(confirmButton).not.toBeDisabled();
        fireEvent.click(confirmButton);

        expect(router.delete).toHaveBeenCalledWith(route('client.flows.destroy', 'flow-42'), expect.anything());
    });
});

describe('the card ⋮ menu — clipping fix (Task 2)', () => {
    it('renders outside the card\'s overflow-hidden container via a Portal, so it can never be clipped by it', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();

        const menu = screen.getByTestId('card-actions-menu');
        const card = screen.getByText('Lead capture').closest('.overflow-hidden');

        // The card still carries overflow-hidden (it needs it, to clip the
        // bottom status banner to the card's rounded corners) — the fix does
        // not touch that. What changed is that the menu is no longer inside it.
        expect(card).not.toBeNull();
        expect(card.contains(menu)).toBe(false);
        expect(menu.parentElement).toBe(document.body);
    });

    it('closes on scroll rather than drifting away from its now-detached trigger', () => {
        render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
        openMenu();
        expect(screen.getByTestId('card-actions-menu')).toBeInTheDocument();

        fireEvent.scroll(window);

        expect(screen.queryByTestId('card-actions-menu')).not.toBeInTheDocument();
    });
});

/**
 * Follow-on positioning fix. The Portal itself (proven above) only stops the
 * menu being CLIPPED; it says nothing about which edge it's anchored to.
 * jsdom never lays elements out for real, so getBoundingClientRect() is
 * mocked per test to a concrete rect and the menu's own computed `left` is
 * checked against exact coordinate math — not just "it's in the DOM".
 */
describe('the card ⋮ menu — right-edge anchoring', () => {
    const MENU_WIDTH = 208; // w-52, must match CardActionsMenu's own constant
    let originalRect;

    const stubTriggerRect = (rect) => {
        originalRect = window.HTMLElement.prototype.getBoundingClientRect;
        window.HTMLElement.prototype.getBoundingClientRect = vi.fn(() => rect);
    };
    const restoreRect = () => { window.HTMLElement.prototype.getBoundingClientRect = originalRect; };

    it('anchors the menu\'s RIGHT edge to the trigger\'s right edge — not its left edge', () => {
        // A trigger near a card's top-right corner, comfortably clear of the viewport's left edge.
        const rect = { top: 40, bottom: 68, left: 300, right: 340, width: 40, height: 28 };
        stubTriggerRect(rect);

        try {
            render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
            openMenu();
            const menu = screen.getByTestId('card-actions-menu');

            // menu.left + MENU_WIDTH === trigger.right — the right edges coincide.
            expect(menu.style.left).toBe(`${rect.right - MENU_WIDTH}px`);
            expect(parseFloat(menu.style.left) + MENU_WIDTH).toBe(rect.right);
            // And structurally NOT left-edge anchored (which would overflow
            // rightward past the trigger, and past the card, by MENU_WIDTH).
            expect(menu.style.left).not.toBe(`${rect.left}px`);
        } finally {
            restoreRect();
        }
    });

    it('falls back to left-anchoring the trigger when right-anchoring would push the menu past the viewport\'s left edge', () => {
        // A trigger near the left edge of a narrow viewport: trigger.right - MENU_WIDTH is negative.
        const rect = { top: 40, bottom: 68, left: 10, right: 50, width: 40, height: 28 };
        stubTriggerRect(rect);

        try {
            render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
            openMenu();
            const menu = screen.getByTestId('card-actions-menu');

            expect(menu.style.left).toBe(`${rect.left}px`);
        } finally {
            restoreRect();
        }
    });
});

/**
 * Vertical flip. Horizontal placement (above) can use a fixed MENU_WIDTH
 * because the menu's width is fixed CSS (`w-52`); height is not fixed the
 * same way, so CardActionsMenu MEASURES it after mount instead of guessing
 * a constant. That means the mock here has to answer differently depending
 * on which element asks: the trigger button (a small rect) vs. the menu
 * itself (the tall one that actually drives the flip decision) — a single
 * fixed return value, like the right-edge tests above use, cannot express
 * that distinction.
 */
describe('the card ⋮ menu — vertical flip', () => {
    let originalRect;
    let originalInnerHeight;

    const stubRects = ({ trigger, menu }) => {
        originalRect = window.HTMLElement.prototype.getBoundingClientRect;
        window.HTMLElement.prototype.getBoundingClientRect = function () {
            return this.getAttribute('data-testid') === 'card-actions-menu' ? menu : trigger;
        };
    };
    const stubInnerHeight = (value) => {
        originalInnerHeight = Object.getOwnPropertyDescriptor(window, 'innerHeight');
        Object.defineProperty(window, 'innerHeight', { value, configurable: true });
    };
    const restore = () => {
        window.HTMLElement.prototype.getBoundingClientRect = originalRect;
        Object.defineProperty(window, 'innerHeight', originalInnerHeight);
    };

    it('opens UPWARD — bottom edge at the trigger\'s top, not top edge at the trigger\'s bottom — when there is not enough room below', () => {
        stubInnerHeight(400);
        // A trigger near the bottom of a 400px-tall viewport, with a 233px-tall menu: only 2px below it.
        const trigger = { top: 370, bottom: 398, left: 300, right: 340, width: 40, height: 28 };
        const menuRect = { top: 0, bottom: 233, left: 0, right: 208, width: 208, height: 233 };
        stubRects({ trigger, menu: menuRect });

        try {
            render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
            openMenu();

            const menu = screen.getByTestId('card-actions-menu');
            expect(menu.style.top).toBe(`${trigger.top - menuRect.height - 4}px`);
            // Structurally NOT the downward placement, which would push the
            // menu's bottom (398 + 4 + 233 = 635) well past the 400px viewport.
            expect(menu.style.top).not.toBe(`${trigger.bottom + 4}px`);
        } finally {
            restore();
        }
    });

    it('positive control: still opens DOWNWARD when there is plenty of room below — no regression', () => {
        stubInnerHeight(1200);
        const trigger = { top: 40, bottom: 68, left: 300, right: 340, width: 40, height: 28 };
        const menuRect = { top: 0, bottom: 233, left: 0, right: 208, width: 208, height: 233 };
        stubRects({ trigger, menu: menuRect });

        try {
            render(<FlowsIndex flows={[flow()]} categories={['LEAD_GENERATION']} />);
            openMenu();

            const menu = screen.getByTestId('card-actions-menu');
            expect(menu.style.top).toBe(`${trigger.bottom + 4}px`);
        } finally {
            restore();
        }
    });
});
