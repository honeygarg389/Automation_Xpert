import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Modal from './Modal';
import Button from './Button';

/**
 * Typed confirmation for an irreversible action.
 *
 * ─── ⚠️ WHY THIS EXISTS AS A NEW SHARED COMPONENT ───────────────────────────
 *
 * The brief said to reuse the existing "Delete workspace?" typed modal. **There
 * is no such component in this codebase** — measured: no workspace-delete route
 * exists at all (`client.workspaces.*` is index/store/switch), and no file
 * anywhere implements a type-to-confirm.
 *
 * The nearest existing thing is the page-local `DeleteConfirmModal` inside
 * `Pages/Admin/Plans/Index.jsx`: shared `Modal` primitives, a red destructive
 * button — but CLICK to confirm, no typing.
 *
 * So this borrows that modal's structure and styling exactly, and adds only the
 * typed gate the ruling asked for. It lives in `Components/ui` rather than beside
 * one page precisely so the next destructive action reuses it instead of making
 * a third variant — which is the failure the reuse rule exists to prevent.
 *
 * ⚠️ NO REASON FIELD. Ruled out deliberately: the action is audit-logged with
 * the actor and the counts, and an input nobody reads is worse than none.
 */
export default function ConfirmDestructiveModal({
    show,
    onClose,
    onConfirm,
    title,
    body,
    confirmWord = 'DELETE',
    confirmLabel,
    processing = false,
}) {
    const { t } = useTranslation();
    const [typed, setTyped] = useState('');

    /**
     * ⚠️ Cleared on the way OUT, not by an effect watching `show`.
     *
     * A previous confirmation must never arm the next one — reopening the modal
     * with "DELETE" still in the box would defeat the whole gate. Doing it in an
     * effect sets state synchronously during render (react-hooks/set-state-in-
     * effect) and causes a cascading render, so the reset rides the close and
     * confirm handlers instead. Every path out of this modal goes through one of
     * the two.
     */
    const close = () => { setTyped(''); onClose(); };
    const confirm = () => { setTyped(''); onConfirm(); };

    const matches = typed === confirmWord;

    return (
        <Modal show={show} onClose={close} maxWidth="sm">
            <Modal.Header title={title} onClose={close} />

            <Modal.Body className="space-y-4">
                <div className="text-sm text-neutral-600 dark:text-neutral-400">{body}</div>

                <div>
                    <label className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {t('common.type_to_confirm', { word: confirmWord })}
                    </label>
                    <input
                        type="text"
                        value={typed}
                        onChange={(e) => setTyped(e.target.value)}
                        autoComplete="off"
                        aria-label={t('common.type_to_confirm', { word: confirmWord })}
                        className="w-full rounded-soft border border-soft border-neutral-300 px-3 py-2 font-mono text-sm text-neutral-900 shadow-inner focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500/20 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-100"
                    />
                </div>
            </Modal.Body>

            <Modal.Footer>
                <Button variant="outline" onClick={close}>{t('common.cancel')}</Button>
                <Button
                    variant="primary"
                    // Destructive styling copied from Admin/Plans' DeleteConfirmModal
                    // rather than invented, so the two read as the same action.
                    className="bg-red-600 text-white hover:bg-red-700"
                    // ⚠️ The gate. Disabled until the word matches exactly — the
                    // whole point of a typed confirmation is that it cannot be
                    // cleared by muscle memory.
                    disabled={! matches || processing}
                    onClick={confirm}
                >
                    {confirmLabel ?? t('common.delete')}
                </Button>
            </Modal.Footer>
        </Modal>
    );
}
