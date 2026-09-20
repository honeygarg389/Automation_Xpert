import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

let clientRole = 'administrator';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { user: { client_role: clientRole } },
            branding: {},
            features: {},
        },
    }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => ({
        'nav.group_messaging': 'Messaging',
        'nav.restaurant_messaging': 'Restaurant Messaging',
    })[key] ?? key }),
}));

import useClientNav from '@/Layouts/useClientNav';

describe('Restaurant Messaging navigation', () => {
    beforeEach(() => {
        clientRole = 'administrator';
        globalThis.route = vi.fn((name) => `/${name}`);
    });

    it('shows Restaurant Messaging in the Messaging group for a workspace administrator', () => {
        const { result } = renderHook(() => useClientNav());
        const messaging = result.current.find((group) => group.label === 'Messaging');
        const item = messaging.items.find((candidate) => candidate.label === 'Restaurant Messaging');

        expect(item).toMatchObject({
            href: '/client.restaurant.messaging.index',
            activePattern: 'client.restaurant.messaging.*',
        });
    });

    it('does not expose the owner-only settings page to client staff', () => {
        clientRole = 'staff';
        const { result } = renderHook(() => useClientNav());
        const messaging = result.current.find((group) => group.label === 'Messaging');

        expect(messaging.items.some((item) => item.label === 'Restaurant Messaging')).toBe(false);
    });
});
