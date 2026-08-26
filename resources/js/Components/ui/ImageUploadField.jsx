import { useEffect, useMemo, useRef } from 'react';
import Button from './Button';

/**
 * A CONTROLLED image field: it holds a File and hands it back, and that is all.
 *
 * ⚠️ THIS IS NOT ImageUploadWidget, AND THE DIFFERENCE IS NOT COSMETIC.
 *
 * `ImageUploadWidget` (Admin/Settings/Index.jsx) POSTs the moment a file is
 * picked, to a route that updates an entity which already exists. That shape
 * cannot work on Create Batch: when the modal is open **the batch does not
 * exist yet**, so there is nothing to attach a logo to and no id to name in a
 * URL. The file has to ride along in the same multipart POST as the other seven
 * fields.
 *
 * So this component never touches the network. It is Input.jsx's sibling —
 * `value` / `onChange`, an `error` slot, a `hint` slot — and it drops into any
 * `useForm` the way `<Input>` does. The parent decides when anything is sent.
 *
 * ⚠️ EVERY STRING IS A PROP, exactly as in Input.jsx and Textarea.jsx. This
 * component does not call `useTranslation()`; the page passes `t()` output in.
 * That is what keeps the design-system primitives free of translation keys.
 *
 * ⚠️ `accept` IS COSMETIC. It filters the OS file dialog and a determined user
 * can defeat it by typing a name or dragging. The real allow-list is the
 * server's `['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048']`, and the
 * stored extension comes from SafeUploadExtension sniffing the content. Nothing
 * here should ever be described as validation.
 */
export default function ImageUploadField({
    label,
    value = null,
    onChange,
    accept = 'image/png,image/jpeg',
    hint,
    error,
    buttonLabel = 'Upload',
    changeLabel,
    removeLabel,
    placeholder,
    id,
}) {
    const inputRef = useRef(null);
    const fieldId = id || 'image-upload-field';

    /**
     * ⚠️ DERIVED, NOT STORED — and the effect below exists ONLY to revoke.
     *
     * `URL.createObjectURL` pins the whole File in memory until the URL is
     * revoked: a 2 MB logo held for the lifetime of the document. Creating it
     * in the change handler (`setPreview(URL.createObjectURL(file))`) leaks one
     * blob every time the user changes their mind, and another when the modal
     * closes.
     *
     * ⚠️ THERE IS NO ESTABLISHED CLEANUP PATTERN TO COPY HERE — measured. Of
     * the seven `createObjectURL` calls in resources/js, the two that preview a
     * picked image (Contacts/Show.jsx:29, Admin/Settings/Index.jsx:180) never
     * revoke at all; only the blob-DOWNLOAD paths do. So this follows the
     * download sites' discipline rather than the preview sites' habit.
     *
     * ⚠️ Written as useMemo + a revoke-only effect rather than
     * useState + useEffect because the state version trips
     * `react-hooks/set-state-in-effect` — correctly: a value fully determined
     * by a prop is derived, not state, and storing it adds a render where the
     * preview is briefly stale.
     */
    const previewUrl = useMemo(() => (value ? URL.createObjectURL(value) : null), [value]);

    // Keyed on the URL itself, so React revokes the previous one before each
    // replacement and once more on unmount — which is the modal closing.
    useEffect(() => () => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
    }, [previewUrl]);

    const pick = () => inputRef.current?.click();

    const handleChange = (e) => {
        onChange?.(e.target.files?.[0] ?? null);

        // ⚠️ Cleared so that re-picking the SAME file fires `change` again. A
        // native file input compares by value and stays silent otherwise, which
        // reads as "the Upload button stopped working" after a Remove.
        e.target.value = '';
    };

    const clear = () => onChange?.(null);

    return (
        <div className="w-full">
            {label && (
                <label
                    htmlFor={fieldId}
                    className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                >
                    {label}
                </label>
            )}

            <div className="flex items-start gap-4">
                <div className="flex flex-col gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={pick}>
                        {value ? (changeLabel ?? buttonLabel) : buttonLabel}
                    </Button>

                    {/* ⚠️ Present ONLY once a file is held. The logo is optional
                        server-side (`nullable`), so without this a user who
                        picks one by mistake can swap it but never get back to
                        "no logo" — and would have to close the modal, losing the
                        other seven fields. */}
                    {value && removeLabel && (
                        <Button type="button" variant="ghost" size="sm" onClick={clear}>
                            {removeLabel}
                        </Button>
                    )}
                </div>

                {/* ⚠️ THE PREVIEW BOX IS `aria-disabled`, NOT `disabled`. It is a
                    div, not a control — `disabled` is inert on it and would tell
                    assistive tech nothing. The greyed appearance and the state
                    are set together so they cannot drift apart. */}
                <div
                    aria-disabled={value ? undefined : 'true'}
                    className={[
                        'flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-soft-lg border-2 border-dashed transition-colors duration-150',
                        value
                            ? 'border-neutral-300 bg-white dark:border-neutral-600 dark:bg-neutral-800'
                            : 'border-neutral-200 bg-neutral-50 opacity-60 dark:border-neutral-700 dark:bg-neutral-800/50',
                    ].join(' ')}
                >
                    {previewUrl ? (
                        <img
                            src={previewUrl}
                            alt={label || 'preview'}
                            className="h-full w-full object-contain p-1"
                        />
                    ) : (
                        <span className="px-2 text-center text-xs text-neutral-400 dark:text-neutral-500">
                            {placeholder}
                        </span>
                    )}
                </div>
            </div>

            {/* Hidden, and driven entirely by the Button above — a bare file
                input cannot be styled to match the design system. */}
            <input
                id={fieldId}
                ref={inputRef}
                type="file"
                accept={accept}
                onChange={handleChange}
                className="hidden"
            />

            {/* ⚠️ Error and hint mirror Input.jsx exactly, including that a hint
                is suppressed while an error is showing. */}
            {error && <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{error}</p>}
            {hint && !error && (
                <p className="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">{hint}</p>
            )}
        </div>
    );
}
