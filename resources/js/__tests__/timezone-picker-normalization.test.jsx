import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key) => key }) }));

const originalSupportedValuesOf = Intl.supportedValuesOf;
Intl.supportedValuesOf = vi.fn(() => [
    'Asia/Calcutta', 'Asia/Kolkata', 'Asia/Katmandu', 'Asia/Kathmandu',
    'Asia/Rangoon', 'Asia/Yangon', 'Asia/Saigon', 'Asia/Ho_Chi_Minh',
    'America/Buenos_Aires', 'America/Argentina/Buenos_Aires',
]);

const { default: TimezonePicker } = await import('@/Components/TimezonePicker');
Intl.supportedValuesOf = originalSupportedValuesOf;

describe('TimezonePicker legacy alias normalization', () => {
    it('offers only PHP-canonical timezone values', () => {
        render(<TimezonePicker value="" onChange={vi.fn()} />);
        fireEvent.click(screen.getByRole('button'));
        for (const [legacy, canonical] of Object.entries({
            'Asia/Calcutta': 'Asia/Kolkata', 'Asia/Katmandu': 'Asia/Kathmandu',
            'Asia/Rangoon': 'Asia/Yangon', 'Asia/Saigon': 'Asia/Ho_Chi_Minh',
            'America/Buenos_Aires': 'America/Argentina/Buenos_Aires',
        })) {
            expect(screen.getByText(canonical.replace(/_/g, ' '))).toBeInTheDocument();
            expect(screen.queryByText(legacy.replace(/_/g, ' '))).not.toBeInTheDocument();
        }
    });
});
