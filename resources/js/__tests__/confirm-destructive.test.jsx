import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k, o) => (o?.word ? `${k}:${o.word}` : k) }),
}));

import ConfirmDestructiveModal from '@/Components/ui/ConfirmDestructiveModal';

/**
 * Slice 3c — the typed confirmation gate.
 *
 * ⚠️ The assertion that matters is that the button is DISABLED until the word
 * matches. "The modal renders" would pass with the gate removed entirely, which
 * is the whole point of the control.
 */
const setup = (onConfirm = vi.fn()) => {
    render(
        <ConfirmDestructiveModal
            show
            onClose={vi.fn()}
            onConfirm={onConfirm}
            title="Delete batch"
            body="This cannot be undone."
        />
    );
    const input = screen.getByLabelText('common.type_to_confirm:DELETE');
    const button = screen.getByText('common.delete').closest('button');

    return { input, button, onConfirm };
};

describe('typed confirmation', () => {
    it('starts disabled, so a stray click cannot delete anything', () => {
        const { button } = setup();
        expect(button.disabled).toBe(true);
    });

    it('stays disabled for a partial or wrong word', () => {
        const { input, button } = setup();

        fireEvent.change(input, { target: { value: 'DELET' } });
        expect(button.disabled).toBe(true);

        fireEvent.change(input, { target: { value: 'delete' } });
        expect(button.disabled).toBe(true, 'Case must matter — lowercase is not the word.');
    });

    it('enables only on an exact match, and fires onConfirm', () => {
        const { input, button, onConfirm } = setup();

        fireEvent.change(input, { target: { value: 'DELETE' } });
        expect(button.disabled).toBe(false);

        fireEvent.click(button);
        expect(onConfirm).toHaveBeenCalledOnce();
    });

    it('does not carry a typed word between openings', () => {
        const { input, button } = setup();
        fireEvent.change(input, { target: { value: 'DELETE' } });
        expect(button.disabled).toBe(false);

        // Cancel clears it — a previous confirmation must never arm the next.
        fireEvent.click(screen.getByText('common.cancel'));
        expect(input.value).toBe('');
    });

    it('has NO reason field — ruled out deliberately', () => {
        setup();
        expect(document.body.querySelectorAll('textarea')).toHaveLength(0);
    });
});
