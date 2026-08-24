/**
 * Multi-line text input. Input.jsx's sibling.
 *
 * ⚠️ EVERY TOKEN HERE IS COPIED FROM Input.jsx ON PURPOSE. This exists because
 * the same class string was being pasted inline at each <textarea> call site,
 * which is how two fields on one screen drift apart. If Input's styling changes,
 * change it here too — they are meant to be indistinguishable.
 *
 * ⚠️ THE ERROR BRANCH OMITS `border-soft` ENTIRELY, and that is not a tidy-up.
 * Input.jsx records why: border-soft's colour utility outranks border-red-500 in
 * the compiled CSS, so leaving it on means the error border silently renders in
 * the normal colour. The hand-rolled textareas this replaces had no error state
 * at all, so a validation failure on default_message showed nothing.
 */
export default function Textarea({
    label,
    error,
    hint,
    rows = 3,
    className = '',
    id,
    ...props
}) {
    const textareaId = id || props.name;
    return (
        <div className="w-full">
            {label && (
                <label
                    htmlFor={textareaId}
                    className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                >
                    {label}
                </label>
            )}
            <textarea
                id={textareaId}
                rows={rows}
                className={[
                    'w-full rounded-soft border bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 shadow-inner transition duration-150 placeholder:text-neutral-400 dark:placeholder:text-neutral-500 focus:outline-none focus:ring-2',
                    error
                        ? 'border-red-500 focus:border-red-500 focus:ring-red-500/20'
                        : 'border-soft border-neutral-300 dark:border-neutral-600 focus:border-brand-500 focus:ring-brand-500/20',
                    className,
                ].filter(Boolean).join(' ')}
                {...props}
            />
            {error && (
                <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{error}</p>
            )}
            {hint && !error && (
                <p className="mt-1.5 text-sm text-neutral-500 dark:text-neutral-400">{hint}</p>
            )}
        </div>
    );
}
