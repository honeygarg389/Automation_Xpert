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
const clearErrors = vi.fn();

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
            clearErrors,
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
    clearErrors.mockClear();
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

    it('renders the Upload Icon/Logo field with the PNG & JPG caption', () => {
        openCreate();

        expect(screen.getByText('smart_qr.section_batch_logo')).toBeInTheDocument();
        expect(screen.getByText('smart_qr.logo_hint')).toBeInTheDocument();
    });
});

/**
 * ⚠️ THE THREE BROWSER-FOUND DEFECTS, PINNED.
 *
 * All three were invisible to 1,315 PHP tests and 53 JS tests: they are
 * layout and environment faults, and the suite asserted behaviour. These
 * assertions are structural on purpose — they are the cheapest thing that
 * fails if the grid or the height cap is undone.
 */
describe('Create Batch — layout regressions found in the browser', () => {
    it('puts Serial Start and Upload Icon/Logo in the SAME grid row', () => {
        // ⚠️ The grid flows in source order, so "same row" means "adjacent
        // children of the 2-column grid, at an even boundary". Asserting the
        // DOM relationship rather than a screenshot: it is the actual mechanism
        // that produces the paired layout.
        openCreate();

        const grid = document.querySelector('.grid.sm\\:grid-cols-2');
        expect(grid).not.toBeNull();

        const children = Array.from(grid.children);
        const serialIdx = children.findIndex((c) => c.textContent.includes('smart_qr.field_serial_start'));
        const logoIdx = children.findIndex((c) => c.textContent.includes('smart_qr.section_batch_logo'));

        expect(serialIdx).toBeGreaterThan(-1);
        expect(logoIdx).toBe(serialIdx + 1);

        // Left column == even index in a 2-col grid. If qr_type ever moves back
        // into slot 6, serialIdx goes odd and this fails.
        expect(serialIdx % 2).toBe(0);
    });

    it('renders the caption beside the Upload button, not below the whole field', () => {
        openCreate();

        const caption = screen.getByText('smart_qr.logo_hint');
        const uploadBtn = screen.getByRole('button', { name: 'smart_qr.logo_upload' });

        // Same column wrapper as the button — the reference places it there,
        // and below the field it reads as a footnote about the preview box too.
        expect(caption.parentElement).toBe(uploadBtn.parentElement);
    });

    /**
     * ⚠️ THE SUBTITLE MUST NOT LIVE IN THE THING THAT SCROLLS.
     *
     * It reads identically in both places until the body overflows — and then
     * it scrolls out of view exactly when the form is longest and the context
     * is most wanted (every field showing an error). Asserting containment
     * rather than appearance, because appearance is the part that looks fine.
     */
    it('renders the subtitle in the sticky header, never in the scrollable body', () => {
        openCreate();

        const subtitle = screen.getByText('smart_qr.create_batch_subtitle');
        const scroller = document.querySelector('[class*="max-h-"]');

        expect(scroller).not.toBeNull();
        expect(scroller.contains(subtitle)).toBe(false);

        // ...and it is under the heading, in the same fixed block.
        const heading = screen.getByRole('heading', { name: 'smart_qr.create_batch' });
        expect(heading.parentElement.contains(subtitle)).toBe(true);
    });

    it('caps the modal body height so header and footer stay reachable', () => {
        // ⚠️ On a 13" MacBook the panel outgrew the viewport and Modal centres
        // with `flex items-center` over `overflow-y-auto`, which pushes the
        // overflow ABOVE the scroll origin — Create and Cancel became
        // unclickable. Mirrors Admin/Plans/PlanModal.jsx.
        openCreate();

        const body = document.querySelector('[class*="max-h-"]');
        expect(body).not.toBeNull();
        expect(body.className).toContain('overflow-y-auto');

        // ⚠️ The cap must be derived from the chrome, not a vh fraction: the
        // panel is body + header + footer + the Modal's own padding, so a
        // fraction of the viewport can still overflow it. See the note at the
        // call site for the arithmetic.
        expect(body.className).toContain('calc(100vh-13rem)');

        // Positive control: it is the BODY that scrolls, not the footer.
        expect(body.textContent).toContain('smart_qr.field_batch_name');
        expect(body.textContent).not.toContain('common.cancel');
    });
});

/**
 * ⚠️ A STALE VALIDATION ERROR IS WORSE THAN NO ERROR.
 *
 * Inertia's useForm holds `errors` until the next submit — `setData` does not
 * clear them. Reported from the browser: an .svg was rejected with "must be a
 * file of type: png, jpg, jpeg", the user replaced it with a valid .png, and
 * the message stayed put. It then names a format the user has already fixed
 * and reads as though the new file failed too.
 *
 * Asserting the clearErrors CALL rather than the absence of text, because the
 * mocked form owns the error state — the call is the actual contract between
 * the field and the form.
 */
describe('Create Batch — a rejected logo must not leave its error behind', () => {
    it('clears the logo error when a different file is picked', () => {
        openCreate();

        const input = document.querySelector('#batch-logo');
        expect(input).not.toBeNull();

        global.URL.createObjectURL = vi.fn(() => 'blob:mock/1');
        global.URL.revokeObjectURL = vi.fn();

        fireEvent.change(input, {
            target: { files: [new File(['x'], 'brand.png', { type: 'image/png' })] },
        });

        expect(clearErrors).toHaveBeenCalledWith('logo');
    });

    /*
     * ⚠️ NO SEPARATE TEST FOR REMOVE, DELIBERATELY.
     *
     * Remove calls the SAME onChange with null, so the assertion above already
     * covers it — and it cannot be exercised here anyway: this file mocks
     * useForm with a plain object, so setData mutates without re-rendering and
     * the Remove control (which only exists while a file is held) never
     * appears. Component-level coverage of Remove lives in
     * image-upload-field.test.jsx, where the value is real React state.
     */

    it('clears every error when the modal is dismissed', () => {
        // Otherwise reopening shows the previous attempt's errors against
        // empty fields. Mirrors AssignQrModal's close() in this module.
        openCreate();

        fireEvent.click(screen.getByRole('button', { name: 'common.cancel' }));

        expect(clearErrors).toHaveBeenCalledWith();
    });
});
