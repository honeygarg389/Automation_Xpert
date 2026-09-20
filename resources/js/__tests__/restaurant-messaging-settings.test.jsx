import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

const put = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial) => ({
        data: initial,
        setData: vi.fn(),
        put,
        processing: false,
        errors: {},
    }),
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import MessagingSettings from '@/Pages/client/Restaurant/MessagingSettings';

describe('Restaurant Messaging settings page', () => {
    it('renders only the supplied workspace outlets with independent controls and clear no-send copy', () => {
        render(<MessagingSettings outlets={[
            { uuid: 'outlet-a', name: 'Downtown', digital_bill_enabled: false, feedback_request_enabled: true },
            { uuid: 'outlet-b', name: 'Airport', digital_bill_enabled: true, feedback_request_enabled: false },
        ]} />);

        expect(screen.getByRole('heading', { name: 'Restaurant Messaging' })).toBeTruthy();
        expect(screen.getByText(/Changing settings does not send any message/i)).toBeTruthy();
        expect(screen.getByText('Downtown')).toBeTruthy();
        expect(screen.getByText('Airport')).toBeTruthy();
        expect(screen.queryByText('Another workspace outlet')).toBeNull();

        expect(screen.getAllByRole('checkbox', { name: /Digital Bill/i })[0]).not.toBeChecked();
        expect(screen.getAllByRole('checkbox', { name: /Feedback Request/i })[0]).toBeChecked();
        expect(screen.getAllByRole('checkbox', { name: /Digital Bill/i })[1]).toBeChecked();
        expect(screen.getAllByRole('checkbox', { name: /Feedback Request/i })[1]).not.toBeChecked();
        expect(screen.getAllByRole('button', { name: 'Save settings' })).toHaveLength(2);
    });
});
