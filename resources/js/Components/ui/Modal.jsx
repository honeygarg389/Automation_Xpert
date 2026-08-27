import { useTranslation } from 'react-i18next';
import {
    Dialog,
    DialogPanel,
    Transition,
    TransitionChild,
} from '@headlessui/react';

/**
 * Modal with soft overlay and rounded panel. Use Modal.Panel for content.
 */
export default function Modal({
    show = false,
    onClose,
    closeable = true,
    maxWidth = '2xl',
    children,
}) {
    const maxWidthClass = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '3xl': 'sm:max-w-3xl',
    }[maxWidth];

    return (
        <Transition show={show} leave="duration-200">
            <Dialog
                as="div"
                className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto px-4 py-6"
                onClose={closeable ? onClose : () => {}}
            >
                <TransitionChild
                    enter="ease-out duration-200"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-neutral-900/40 backdrop-blur-[2px]" />
                </TransitionChild>

                <TransitionChild
                    enter="ease-out duration-200"
                    enterFrom="opacity-0 scale-95"
                    enterTo="opacity-100 scale-100"
                    leave="ease-in duration-150"
                    leaveFrom="opacity-100 scale-100"
                    leaveTo="opacity-0 scale-95"
                >
                    <DialogPanel
                        className={`relative w-full overflow-hidden rounded-soft-lg bg-white dark:bg-neutral-900 shadow-soft-xl border border-soft border-neutral-200 dark:border-neutral-800 transition-all sm:mx-auto ${maxWidthClass} text-neutral-900 dark:text-neutral-100`}
                    >
                        {children}
                    </DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}

/**
 * ⚠️ THE HEADER IS OUTSIDE THE SCROLL CONTAINER, WHICH IS WHY A SUBTITLE
 * BELONGS HERE RATHER THAN AT THE TOP OF THE BODY.
 *
 * Nothing here is `position: sticky`, and it does not need to be: Header, Body
 * and Footer are siblings inside the panel, and only the Body is given
 * `overflow-y-auto` by its call site. So the Header is fixed *structurally* —
 * content placed in it cannot scroll away, because it is not in the thing that
 * scrolls. A paragraph at the top of the Body looks identical until the body
 * overflows, and then it scrolls out of view exactly when the form is longest
 * and the context is most needed (the all-fields-in-error state).
 *
 * @param subtitle Optional second line under the title. OPTIONAL, and the
 *   alignment below is conditional on it, so the 17 other call sites that pass
 *   no subtitle render byte-identically to before — `items-center` is correct
 *   for a single-line header and `items-start` is correct for a two-line one.
 */
Modal.Header = function ModalHeader({ title, subtitle, onClose, showClose = true }) {
    const { t } = useTranslation();
    return (
        <div className={`flex ${subtitle ? 'items-start' : 'items-center'} justify-between border-b border-soft border-neutral-200 dark:border-neutral-800 px-5 py-4`}>
            <div className="min-w-0">
                <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{title}</h3>
                {subtitle && (
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{subtitle}</p>
                )}
            </div>
            {showClose && onClose && (
                <button
                    type="button"
                    onClick={onClose}
                    className="shrink-0 rounded-soft p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 dark:hover:text-neutral-300 transition duration-150"
                    aria-label={t('common.close')}
                >
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            )}
        </div>
    );
};

Modal.Body = function ModalBody({ className = '', ...props }) {
    return <div className={`px-5 py-4 ${className}`} {...props} />;
};

Modal.Footer = function ModalFooter({ className = '', ...props }) {
    return (
        <div className={`flex items-center justify-end gap-3 border-t border-soft border-neutral-200 dark:border-neutral-800 px-5 py-4 ${className}`} {...props} />
    );
};
