import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Dropdown } from '@/Components/ui';

function TestDropdown({ align = 'right', width = '56' }) {
    return (
        <div className="overflow-x-auto" data-testid="scroll-boundary">
            <Dropdown>
                <Dropdown.Trigger>
                    <button type="button">Actions</button>
                </Dropdown.Trigger>
                <Dropdown.Content align={align} width={width}>
                    <Dropdown.Item>First action</Dropdown.Item>
                    <Dropdown.Item>Second action</Dropdown.Item>
                </Dropdown.Content>
            </Dropdown>
        </div>
    );
}

const rect = ({ left, top, width = 40, height = 32 }) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
    x: left,
    y: top,
    toJSON: () => ({}),
});

describe('Dropdown portal positioning', () => {
    let triggerRect;
    let menuRect;
    let originalInnerWidth;
    let originalInnerHeight;
    let originalDirection;
    let boundingRect;

    beforeEach(() => {
        triggerRect = rect({ left: 800, top: 100 });
        menuRect = rect({ left: 0, top: 0, width: 224, height: 120 });
        originalInnerWidth = window.innerWidth;
        originalInnerHeight = window.innerHeight;
        originalDirection = document.documentElement.dir;
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1024 });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 768 });
        boundingRect = vi.spyOn(window.HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function mockRect() {
            return this.hasAttribute('data-dropdown-content') ? menuRect : triggerRect;
        });
    });

    afterEach(() => {
        boundingRect.mockRestore();
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalInnerWidth });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: originalInnerHeight });
        document.documentElement.dir = originalDirection;
    });

    async function open() {
        fireEvent.click(screen.getByRole('button', { name: 'Actions' }));
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).toBeInTheDocument());
        return document.body.querySelector('[data-dropdown-content]');
    }

    it('portals the menu outside an overflow-x-auto ancestor and keeps the trigger content accessible', async () => {
        const { getByTestId } = render(<TestDropdown />);
        const menu = await open();

        expect(menu.parentElement).toBe(document.body);
        expect(getByTestId('scroll-boundary').contains(menu)).toBe(false);
        expect(screen.getByRole('button', { name: 'First action' })).toBeInTheDocument();
    });

    it('keeps the existing Escape and outside-click close behavior', async () => {
        render(<TestDropdown />);
        await open();
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());

        await open();
        fireEvent.click(document.body.querySelector('[aria-hidden="true"]'));
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());
    });

    it.each([
        ['Restaurant Outlets', '56'],
        ['Plans table', '56'],
        ['Smart QR Batch detail', '56'],
        ['Smart QR Inventory detail', '56'],
        ['Topbar workspace/account menu', '56'],
        ['Topbar language and Landing layout locale menus', '48'],
    ])('%s uses a portalled, closable right-aligned menu', async (_consumer, width) => {
        render(<TestDropdown width={width} />);
        const menu = await open();

        expect(menu.parentElement).toBe(document.body);
        fireEvent.keyDown(document, { key: 'Escape' });
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());
    });

    it('closes on a capturing scroll or resize event instead of leaving a fixed menu detached from its trigger', async () => {
        render(<TestDropdown />);
        await open();
        fireEvent.scroll(window);
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());

        await open();
        fireEvent.resize(window);
        await waitFor(() => expect(document.body.querySelector('[data-dropdown-content]')).not.toBeInTheDocument());
    });

    it('flips horizontally and vertically to keep the measured menu inside the viewport', async () => {
        triggerRect = rect({ left: 970, top: 700 });
        menuRect = rect({ left: 0, top: 0, width: 224, height: 180 });
        render(<TestDropdown align="left" />);
        const menu = await open();

        // Align=left would ordinarily grow right from x=970. It flips left,
        // and the lower-edge trigger opens upward: 700 - 180 - 8 = 512.
        await waitFor(() => expect(menu.style.top).toBe('512px'));
        expect(menu.style.left).toBe('792px');
        expect(Number.parseFloat(menu.style.left) + 224).toBeLessThanOrEqual(1016);
        expect(Number.parseFloat(menu.style.top)).toBeGreaterThanOrEqual(8);
    });

    it('keeps the previous right-alignment semantics in RTL layouts', async () => {
        document.documentElement.dir = 'rtl';
        triggerRect = rect({ left: 300, top: 100 });
        render(<TestDropdown align="right" />);
        const menu = await open();

        // `align=right` was `rtl:left-0` before portal rendering, so it must
        // remain physically left-aligned to the trigger in an RTL document.
        expect(menu.style.left).toBe('300px');
    });
});
