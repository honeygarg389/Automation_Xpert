import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useState } from 'react';
import ImageUploadField from '@/Components/ui/ImageUploadField';

/**
 * ⚠️ WHAT THIS FILE EXISTS TO PIN.
 *
 *   controlled      the field holds NOTHING of its own — a File goes in via
 *                   `value`, comes back out via `onChange`, and nothing is
 *                   posted. The whole reason this is not ImageUploadWidget.
 *   preview state   greyed with no file, showing the image once one is picked
 *   no leak         every object URL created is revoked — on replacement AND
 *                   on unmount
 *
 * ⚠️ jsdom implements neither createObjectURL nor revokeObjectURL, so they are
 * stubbed here. That is not a workaround around the assertion — the stubs ARE
 * the instrument: counting create against revoke is the only way to observe a
 * blob leak, which is invisible to the DOM.
 */

let created = [];
let revoked = [];

beforeEach(() => {
    created = [];
    revoked = [];
    let n = 0;
    global.URL.createObjectURL = vi.fn(() => {
        const url = `blob:mock/${++n}`;
        created.push(url);

        return url;
    });
    global.URL.revokeObjectURL = vi.fn((url) => revoked.push(url));
});

const png = (name = 'logo.png') => new File(['x'], name, { type: 'image/png' });

/** A parent that owns the value — the shape CreateBatchModal's useForm gives. */
function Harness({ onChange, initial = null }) {
    const [file, setFile] = useState(initial);

    return (
        <ImageUploadField
            label="Icon / Logo"
            value={file}
            onChange={(f) => { setFile(f); onChange?.(f); }}
            hint="Upload Only PNG & JPG."
            buttonLabel="Upload"
            changeLabel="Change"
            removeLabel="Remove"
            placeholder="Preview"
        />
    );
}

describe('ImageUploadField — controlled contract', () => {
    it('renders the label, hint and upload button', () => {
        render(<Harness />);
        expect(screen.getByText('Icon / Logo')).toBeInTheDocument();
        expect(screen.getByText('Upload Only PNG & JPG.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Upload' })).toBeInTheDocument();
    });

    it('hands the File back through onChange and stores nothing itself', () => {
        const onChange = vi.fn();
        const { container } = render(<Harness onChange={onChange} />);

        const file = png();
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [file] } });

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange.mock.calls[0][0]).toBe(file);
    });

    it('never posts — no network call is made on selection', () => {
        // ⚠️ THE DISCRIMINATOR AGAINST ImageUploadWidget, which posts on select.
        // If this component ever gained a router.post, `fetch` is the closest
        // observable jsdom gives us; the structural proof is the absence of any
        // router import, asserted in the build/lint pass.
        const spy = vi.fn();
        global.fetch = spy;

        const { container } = render(<Harness />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        expect(spy).not.toHaveBeenCalled();
    });

    it('clears the native input so the same file can be re-picked', () => {
        // A file input compares by value and stays silent on a repeat pick,
        // which reads as "Upload stopped working" after a Remove.
        //
        // ⚠️ THE REDEFINED PROPERTY IS THE INSTRUMENT, NOT A WORKAROUND.
        // jsdom forbids assigning to a file input's `value`, so it reads `''`
        // whatever the handler does — the naive `expect(input.value).toBe('')`
        // is a DEAD ASSERTION that passes with the clearing line deleted
        // (mutation-checked: it did). Seeding a writable `value` makes the
        // write observable, which is the only way to see this behaviour at all.
        const { container } = render(<Harness />);
        const input = container.querySelector('input[type="file"]');

        let stored = 'C:\\fakepath\\logo.png';
        Object.defineProperty(input, 'value', {
            get: () => stored,
            set: (v) => { stored = v; },
            configurable: true,
        });

        expect(input.value).not.toBe('');

        fireEvent.change(input, { target: { files: [png()] } });

        expect(input.value).toBe('');
    });
});

describe('ImageUploadField — preview state', () => {
    it('is greyed and shows no image before a file is picked', () => {
        render(<Harness />);

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.getByText('Preview')).toBeInTheDocument();

        const box = screen.getByText('Preview').parentElement;
        expect(box).toHaveAttribute('aria-disabled', 'true');
        expect(box.className).toContain('opacity-60');
    });

    it('shows the image and drops the disabled state once a file is picked', () => {
        const { container } = render(<Harness />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        const img = screen.getByRole('img');
        expect(img).toBeInTheDocument();
        expect(img).toHaveAttribute('src', created[0]);

        expect(screen.queryByText('Preview')).not.toBeInTheDocument();
        expect(img.closest('[aria-disabled]')).toBeNull();
    });

    it('swaps the button to the change label and offers Remove once held', () => {
        const { container } = render(<Harness />);
        expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();

        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        expect(screen.getByRole('button', { name: 'Change' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument();
    });

    /**
     * ⚠️ THE ICONS ARE ASSERTED BY STATE, not merely "an svg is present".
     *
     * Upload and Change are the SAME button; only the icon and the word differ.
     * A swap that renders the wrong one is invisible to every other assertion
     * here, and it is the difference between "add a logo" and "replace the
     * logo" — the second is destructive.
     */
    it('shows Upload while empty and RefreshCw once a file is held', () => {
        const { container } = render(<Harness />);

        expect(container.querySelector('.lucide-upload')).not.toBeNull();
        expect(container.querySelector('.lucide-refresh-cw')).toBeNull();

        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        expect(container.querySelector('.lucide-refresh-cw')).not.toBeNull();
        expect(container.querySelector('.lucide-upload')).toBeNull();
    });

    /**
     * ⚠️ THE TRASH BUTTON MUST SIT OUTSIDE THE CLIPPED BOX.
     *
     * The preview box is `overflow-hidden` so the image respects the rounded
     * corners — which would also clip a negatively-positioned child. The button
     * therefore belongs to the WRAPPER, not to the box. Asserting the DOM
     * relationship because jsdom cannot show the clipping itself.
     */
    it('renders Remove as a Trash2 icon button overlapping the preview corner', () => {
        const { container } = render(<Harness />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        const remove = screen.getByRole('button', { name: 'Remove' });

        // Icon-only: the accessible name has to come from aria-label now.
        expect(remove).toHaveAttribute('aria-label', 'Remove');
        expect(remove.textContent).toBe('');
        expect(remove.querySelector('.lucide-trash-2')).not.toBeNull();

        // Positioned over the corner...
        expect(remove.className).toContain('absolute');
        expect(remove.className).toContain('-right-2');
        expect(remove.className).toContain('-top-2');

        // ...and NOT inside the overflow-hidden box, which would clip it.
        const clipped = container.querySelector('.overflow-hidden');
        expect(clipped).not.toBeNull();
        expect(clipped.contains(remove)).toBe(false);
    });

    it('Trash2 clears the file exactly as the old text control did', () => {
        const onChange = vi.fn();
        const { container } = render(<Harness onChange={onChange} />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        expect(onChange).toHaveBeenLastCalledWith(null);
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
    });

    it('Remove returns the field to empty and reports null upward', () => {
        // ⚠️ The logo is `nullable` server-side. Without a way back to null, a
        // mistaken pick could only be swapped, never undone — the user would
        // have to close the modal and lose the other seven fields.
        const onChange = vi.fn();
        const { container } = render(<Harness onChange={onChange} />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        expect(onChange).toHaveBeenLastCalledWith(null);
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(screen.getByText('Preview')).toBeInTheDocument();
    });
});

describe('ImageUploadField — object URL lifecycle', () => {
    it('revokes the previous URL when the file is replaced', () => {
        const { container } = render(<Harness />);
        const input = container.querySelector('input[type="file"]');

        fireEvent.change(input, { target: { files: [png('a.png')] } });
        fireEvent.change(input, { target: { files: [png('b.png')] } });

        expect(created).toHaveLength(2);
        expect(revoked).toContain(created[0]);
    });

    it('revokes on unmount, so closing the modal leaks nothing', () => {
        const { container, unmount } = render(<Harness />);
        fireEvent.change(container.querySelector('input[type="file"]'), { target: { files: [png()] } });

        expect(revoked).toHaveLength(0);
        unmount();

        // ⚠️ The whole assertion: every URL created has been revoked. The two
        // existing preview sites in this codebase would fail this.
        expect(revoked.sort()).toEqual(created.sort());
    });

    it('creates no object URL at all while empty', () => {
        render(<Harness />);
        expect(created).toHaveLength(0);
    });
});

describe('ImageUploadField — error and hint slots', () => {
    it('renders a server error', () => {
        render(<ImageUploadField label="Logo" value={null} onChange={() => {}} error="The logo field must be a file of type: png, jpg, jpeg." />);
        expect(screen.getByText(/must be a file of type/)).toBeInTheDocument();
    });

    it('suppresses the hint while an error is showing, matching Input.jsx', () => {
        render(<ImageUploadField label="Logo" value={null} onChange={() => {}} hint="Upload Only PNG & JPG." error="Too large." />);
        expect(screen.getByText('Too large.')).toBeInTheDocument();
        expect(screen.queryByText('Upload Only PNG & JPG.')).not.toBeInTheDocument();
    });
});
