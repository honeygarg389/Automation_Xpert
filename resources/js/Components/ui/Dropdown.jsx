import { Fragment, createContext, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';

const DropdownContext = createContext();
const VIEWPORT_MARGIN = 8;
const MENU_GAP = 8;
const WIDTHS = { 48: 192, 56: 224, 64: 256 };

export default function Dropdown({ children }) {
    const [open, setOpen] = useState(false);
    const triggerRef = useRef(null);

    // Escape-to-close: every caller of this shared component gets it for
    // free, rather than each row-actions menu having to reimplement its own
    // keydown handling. Only listens while actually open.
    useEffect(() => {
        if (!open) return undefined;

        const handleKeyDown = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [open]);

    return (
        <DropdownContext.Provider value={{ open, setOpen, triggerRef }}>
            <div className="relative">{children}</div>
        </DropdownContext.Provider>
    );
}

function Trigger({ children }) {
    const { open, setOpen, triggerRef } = useContext(DropdownContext);

    return (
        <div ref={triggerRef} onClick={() => setOpen(!open)}>{children}</div>
    );
}

function Content({ align = 'right', width = '48', children }) {
    const { open, setOpen, triggerRef } = useContext(DropdownContext);
    const menuRef = useRef(null);
    const [geometry, setGeometry] = useState(null);
    const [menuHeight, setMenuHeight] = useState(null);
    const menuWidth = WIDTHS[width] ?? WIDTHS[64];
    const widthClass = width === '48' ? 'w-48' : width === '56' ? 'w-56' : 'w-64';

    useEffect(() => {
        if (!open || !triggerRef.current) return undefined;

        const rect = triggerRef.current.getBoundingClientRect();
        const isRtl = document.documentElement.dir === 'rtl';
        const leftAligned = (align === 'left') !== isRtl;
        const preferredLeft = leftAligned ? rect.left : rect.right - menuWidth;
        const maxLeft = Math.max(VIEWPORT_MARGIN, window.innerWidth - menuWidth - VIEWPORT_MARGIN);
        const left = Math.min(Math.max(preferredLeft, VIEWPORT_MARGIN), maxLeft);

        setGeometry({ left, triggerTop: rect.top, triggerBottom: rect.bottom });
        setMenuHeight(null);

        // Fixed portal coordinates do not follow a scrolling trigger. Closing
        // mirrors the established Flows menu behaviour and avoids visual drift.
        const close = () => setOpen(false);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);
        return () => {
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [align, menuWidth, open, setOpen, triggerRef]);

    const measureMenu = useCallback((node) => {
        menuRef.current = node;
        if (!open || !geometry || !node) return;

        const height = node.getBoundingClientRect().height;
        setMenuHeight((current) => current === height ? current : height);
    }, [geometry, open]);

    // The menu's height depends on its caller-provided children, so measure it
    // after portal mount before choosing whether it opens above or below.
    useLayoutEffect(() => {
        if (!open || !geometry || !menuRef.current) return;
        setMenuHeight(menuRef.current.getBoundingClientRect().height);
    }, [geometry, open]);

    if (typeof document === 'undefined') return null;

    const spaceBelow = geometry ? window.innerHeight - geometry.triggerBottom - VIEWPORT_MARGIN : 0;
    const spaceAbove = geometry ? geometry.triggerTop - VIEWPORT_MARGIN : 0;
    const opensUpward = geometry && menuHeight !== null
        && menuHeight + MENU_GAP > spaceBelow
        && spaceAbove > spaceBelow;
    const naturalTop = geometry && (opensUpward
        ? geometry.triggerTop - (menuHeight ?? 0) - MENU_GAP
        : geometry.triggerBottom + MENU_GAP);
    const maxTop = Math.max(VIEWPORT_MARGIN, window.innerHeight - VIEWPORT_MARGIN - (menuHeight ?? 0));
    const top = naturalTop === null || naturalTop === false
        ? 0
        : Math.min(Math.max(naturalTop, VIEWPORT_MARGIN), maxTop);

    return createPortal(
        <>
            {open && <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} aria-hidden="true" />}
            <Transition
                show={open && geometry !== null}
                as={Fragment}
                enter="transition ease-out duration-150"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-100"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <div
                    ref={measureMenu}
                    data-dropdown-content
                    style={{ position: 'fixed', top, left: geometry?.left ?? 0 }}
                    className={`z-50 ${widthClass} max-h-[calc(100vh-1rem)] overflow-y-auto rounded-soft-lg border border-soft border-gray-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 py-1 shadow-soft-lg dark:shadow-none`}
                    onClick={() => setOpen(false)}
                >
                    {children}
                </div>
            </Transition>
        </>,
        document.body,
    );
}

function Item({ as = 'button', className = '', children, ...props }) {
    const base = 'block w-full px-4 py-2.5 text-left rtl:text-right text-sm text-neutral-700 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800 transition duration-150 first:rounded-t-soft last:rounded-b-soft';
    if (as === 'link') {
        return (
            <Link className={`${base} ${className}`} {...props}>
                {children}
            </Link>
        );
    }
    return (
        <button type="button" className={`${base} ${className}`} {...props}>
            {children}
        </button>
    );
}

function Divider() {
    return <div className="my-1 border-t border-soft border-gray-200 dark:border-neutral-700" />;
}

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Item = Item;
Dropdown.Divider = Divider;
