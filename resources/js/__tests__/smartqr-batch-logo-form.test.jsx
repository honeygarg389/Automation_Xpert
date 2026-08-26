import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * ⚠️ THE ONE THAT CATCHES A SILENT DROP.
 *
 * Inertia serialises a visit to JSON unless it detects a File **or** is told
 * `forceFormData: true`. A `File` cannot survive JSON. So if that flag is ever
 * removed from the batch-create submit, the logo is discarded in the browser:
 * the request still succeeds, the batch is still created, no validation error
 * is raised anywhere — the run simply prints unbranded, and it is discovered in
 * a box of stickers.
 *
 * Nothing else in either suite can see that. The PHP tests post multipart
 * directly and never exercise Inertia's serialisation; the component tests
 * never reach the page. This asserts the OPTIONS OBJECT THE PAGE PASSES,
 * because that is where the decision is actually made.
 *
 * ⚠️ It also pins the field NAME. `logo` is what StoreQrBatchRequest validates
 * and what QrBatchController reads via `$request->file('logo')`; a rename here
 * fails silently in exactly the same way.
 */

const formPosts = [];
let formData = {};
const setData = vi.fn((k, v) => { formData[k] = v; });

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { permissions: ['manage_qr_batches', 'view_qr_inventory'] },
            flash: {},
            errors: {},
            timezone: 'UTC',
        },
        url: '/admin/qr/batches',
    }),
    router: { post: vi.fn(), get: vi.fn(), delete: vi.fn() },
    useForm: (initial) => {
        // The initial shape is captured once, then mutated by setData — the
        // same contract the real useForm offers the page.
        if (Object.keys(formData).length === 0) formData = { ...initial };

        return {
            data: formData,
            setData,
            post: (url, opts) => formPosts.push({ url, opts }),
            processing: false,
            errors: {},
            reset: vi.fn(),
            clearErrors: vi.fn(),
        };
    },
    Head: () => null,
    Link: ({ href, children, ...p }) => <a href={href} {...p}>{children}</a>,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (k) => k }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

import SmartQrBatchesIndex from '@/Pages/Admin/SmartQr/Batches/Index';

const renderPage = () =>
    render(<SmartQrBatchesIndex batches={{ data: [], links: [] }} />);

beforeEach(() => {
    formPosts.length = 0;
    formData = {};
    setData.mockClear();
});

const openCreate = () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: /smart_qr\.create_batch/ }));
};

describe('Create Batch — the logo rides in the same multipart POST', () => {
    it('submits with forceFormData: true', () => {
        openCreate();

        // ⚠️ fireEvent.submit ON THE FORM, not a click on the submit button.
        // jsdom does not run implicit form submission from a button click, so
        // the click version recorded zero posts and the assertion below failed
        // for a reason that had nothing to do with the code under test.
        const form = document.querySelector('form');
        expect(form).not.toBeNull();
        fireEvent.submit(form);

        expect(formPosts).toHaveLength(1);
        expect(formPosts[0].opts.forceFormData).toBe(true);
    });

    it('registers `logo` in the form state, initialised to null', () => {
        openCreate();

        expect(formData).toHaveProperty('logo', null);
    });

    it('leaves the other seven fields untouched', () => {
        // ⚠️ POSITIVE CONTROL for the assertion above: proves the form shape was
        // read at all, and that adding `logo` renamed nothing.
        openCreate();

        for (const key of [
            'batch_name', 'batch_number', 'prefix', 'quantity',
            'serial_start', 'qr_type', 'default_message',
        ]) {
            expect(formData).toHaveProperty(key);
        }
    });

    it('setData is called with the `logo` key when a file is picked', () => {
        openCreate();

        const input = document.querySelector('#batch-logo');
        expect(input).not.toBeNull();

        const file = new File(['x'], 'brand.png', { type: 'image/png' });
        global.URL.createObjectURL = vi.fn(() => 'blob:mock/1');
        global.URL.revokeObjectURL = vi.fn();

        fireEvent.change(input, { target: { files: [file] } });

        expect(setData).toHaveBeenCalledWith('logo', file);
    });

    it('renders the Upload Icon/Logo section with the PNG & JPG caption', () => {
        openCreate();

        expect(screen.getByText('smart_qr.section_batch_logo')).toBeInTheDocument();
        expect(screen.getByText('smart_qr.logo_hint')).toBeInTheDocument();
    });
});
