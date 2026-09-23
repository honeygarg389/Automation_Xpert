import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';

const submissions = [];
const formStates = [];
const putCalls = [];

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { get: vi.fn() },
    useForm: (initial) => {
        const data = { ...initial };
        formStates.push(data);

        return {
            data,
            setData: vi.fn(),
            post: (url, options) => submissions.push({ url, options }),
            put: (url, options) => putCalls.push({ url, options }),
            processing: false,
            errors: {},
        };
    },
}));

vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/Components/ui', () => ({
    Badge: ({ children }) => <span>{children}</span>,
    Button: ({ children, ...props }) => <button {...props}>{children}</button>,
    Card: ({ children }) => <div>{children}</div>,
    Input: ({ label, error: _error, ...props }) => <label>{label}<input {...props} /></label>,
    Textarea: ({ label, error: _error, ...props }) => <label>{label}<textarea {...props} /></label>,
    Select: ({ label, options = [], items = [], ...props }) => <label>{label}<select {...props}>{[...options, ...items].map((item) => <option key={item.value ?? item.id} value={item.value ?? item.id}>{item.label}</option>)}</select></label>,
}));

import ProfileMessaging from '@/Pages/client/Restaurant/ProfileMessaging';
import RestaurantBranding from '@/Pages/Admin/Restaurant/Branding';

const profile = {};

beforeEach(() => {
    submissions.length = 0;
    formStates.length = 0;
    putCalls.length = 0;
});

afterEach(cleanup);

function assertMultipartMethodSpoof() {
    expect(formStates).toHaveLength(1);
    expect(formStates[0]).toMatchObject({ logo: null, cover: null, _method: 'put' });
    expect(submissions).toHaveLength(1);
    expect(submissions[0].options).toMatchObject({ forceFormData: true, preserveScroll: true });
    expect(putCalls).toHaveLength(0);
}

describe('Restaurant brand-profile multipart submits', () => {
    it('uses POST plus _method=put for the client Profile & Messaging form', () => {
        render(<ProfileMessaging profile={profile} outlets={[]} digitalBillDeliveryOptions={{ senders: [] }} feedbackDeliveryOptions={{ senders: [] }} feedbackTimingPreferences={[]} />);

        fireEvent.submit(screen.getByRole('button', { name: 'Save business profile' }).closest('form'));

        assertMultipartMethodSpoof();
        expect(submissions[0].url).toContain('client.restaurant.profile-messaging.profile.update');
    });

    it('uses POST plus _method=put for the admin Restaurant Branding form', () => {
        render(<RestaurantBranding workspace={{ id: 42, name: 'North Kitchen' }} workspaces={[]} profile={profile} outlets={[]} />);

        fireEvent.submit(screen.getByRole('button', { name: 'Save branding' }).closest('form'));

        assertMultipartMethodSpoof();
        expect(submissions[0].url).toContain('admin.restaurant.branding.update');
    });
});
