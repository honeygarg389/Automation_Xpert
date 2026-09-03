import { useState } from 'react';

/**
 * Toggle switch with smooth transition. Controlled when onChange is provided.
 *
 * ⚠️ FOCUS RING IS focus-visible, NOT focus. A <button> keeps focus after a click,
 * so a plain `focus:ring-2` stayed lit after every toggle — permanently, in a table
 * of them. focus-visible shows it for keyboard navigation and not for mouse clicks,
 * which keeps the ring where it is needed (tabbing through PlanForm and
 * PaymentGateways) without painting it on every row someone clicks.
 *
 * ⚠️ THE TRANSLATE VALUES ARE DERIVED, NOT PICKED. Track h-6 w-11 with a 1px border
 * gives a 22 x 42px content box. A 16px knob (h-4 w-4) leaves 3px on every side, so
 * the off position is 3px and the on position is 42 - 16 - 3 = 23px. Change the track
 * size or the knob and these must be recomputed — a stale pair leaves the knob
 * visibly off-centre at one end.
 *
 * ⚠️ `items-center` ON THE TRACK IS LOAD-BEARING, not decoration. Without it the knob
 * aligns to the top of the track: measured 0px above and 6px below. The old 20px knob
 * hid this because it nearly filled the 22px box; shrinking it exposed the bug. The
 * items-center that was already present sits on the outer <label>, which centres the
 * track against its text label — a different axis, and no help here.
 */
export default function Toggle({
    checked,
    defaultChecked = false,
    onChange,
    disabled = false,
    label,
    className = '',
}) {
    const [uncontrolled, setUncontrolled] = useState(defaultChecked);
    const isOn = onChange ? (checked ?? false) : uncontrolled;

    const handleClick = () => {
        if (disabled) return;
        const next = !isOn;
        if (onChange) onChange(next);
        else setUncontrolled(next);
    };

    return (
        <label className={`inline-flex items-center gap-3 cursor-pointer ${disabled ? 'opacity-50 cursor-not-allowed' : ''} ${className}`}>
            <button
                type="button"
                role="switch"
                aria-checked={isOn}
                disabled={disabled}
                onClick={handleClick}
                className={[
                    'relative inline-flex items-center h-6 w-11 shrink-0 rounded-full border border-soft transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/30 focus-visible:ring-offset-1',
                    isOn ? 'bg-brand-500 border-brand-500' : 'bg-neutral-200 dark:bg-neutral-700 border-neutral-300 dark:border-neutral-600',
                ].join(' ')}
            >
                <span
                    className={[
                        'pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-soft transition duration-200',
                        isOn ? 'translate-x-[23px]' : 'translate-x-[3px]',
                    ].join(' ')}
                />
            </button>
            {label && (
                <span className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</span>
            )}
        </label>
    );
}
