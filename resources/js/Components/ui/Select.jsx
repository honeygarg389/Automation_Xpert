/**
 * Select with soft border and consistent styling.
 */
export default function Select({
    label,
    error,
    options = [],
    placeholder = 'Select...',
    className = '',
    id,
    ...props
}) {
    const selectId = id || props.name;
    return (
        <div className="w-full">
            {label && (
                <label
                    htmlFor={selectId}
                    className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                >
                    {label}
                </label>
            )}
            <select
                id={selectId}
                // ⚠️ Browsers (Chrome in particular) will silently override a
                // deliberately-unselected controlled value on a <select> that
                // LOOKS like part of an address form — heuristically matched
                // by nearby label text ("Country") plus a sibling "Address"
                // field, with NO `name="country"` needed to trigger it. This
                // is exactly how "Default phone country" showed India
                // pre-selected on a fresh Petpooja connection form with zero
                // user interaction: React's `value=""` was correct the whole
                // time, but the browser's own autofill painted a different
                // option as selected in the DOM out from under it.
                // `autoComplete="off"` is the standard opt-out for this class
                // of field; every <select> in this shared component gets it,
                // since none of them are meant to be browser-autofilled.
                autoComplete="off"
                className={[
                    'w-full rounded-soft border border-soft border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-neutral-900 dark:text-neutral-100 shadow-inner transition duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20',
                    error && 'border-red-500 focus:border-red-500 focus:ring-red-500/20',
                    className,
                ].filter(Boolean).join(' ')}
                {...props}
            >
                {placeholder && (
                    <option value="">{placeholder}</option>
                )}
                {options.map((opt) =>
                    typeof opt === 'object' ? (
                        <option key={opt.value} value={opt.value}>
                            {opt.label}
                        </option>
                    ) : (
                        <option key={opt} value={opt}>{opt}</option>
                    )
                )}
            </select>
            {error && (
                <p className="mt-1.5 text-sm text-red-500 dark:text-red-400">{error}</p>
            )}
        </div>
    );
}
