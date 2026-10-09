import { useCallback, useState, type ReactNode } from 'react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface ConfirmOptions {
    title: string;
    description?: ReactNode;
    /** Extra emphasis lines shown in a tinted box (e.g. what will happen to related data). */
    note?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** 'danger' = destructive (red, Cancel is focused first); 'primary' = a normal confirmation. */
    tone?: 'danger' | 'primary';
    onConfirm: () => void;
}

/** A proper confirmation modal, used instead of the browser's built-in confirm() prompt. */
export function ConfirmDialog({ options, onClose }: { options: ConfirmOptions | null; onClose: () => void }) {
    const danger = (options?.tone ?? 'primary') === 'danger';

    return (
        <Dialog open={options !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-sm p-0" onOpenAutoFocus={(e) => {
                // focus the safe choice (Cancel) for destructive actions
                if (danger) {
                    e.preventDefault();
                    (e.currentTarget as HTMLElement).querySelector<HTMLButtonElement>('[data-cancel]')?.focus();
                }
            }}>
                {options && (
                    <>
                        <div className="px-6 pb-2 pt-6 text-center">
                            <div className={cn('mx-auto mb-3 grid size-11 place-items-center rounded-full', danger ? 'bg-[#ffe9e2] text-destructive' : 'bg-[#e8ecfb] text-secondary')}>
                                {danger ? <AlertTriangle className="size-5" /> : <CheckCircle2 className="size-5" />}
                            </div>
                            <DialogTitle className="text-base">{options.title}</DialogTitle>
                            {options.description && <DialogDescription className="mt-1.5 leading-relaxed">{options.description}</DialogDescription>}
                            {options.note && (
                                <div className={cn('mt-3 rounded-md border px-3 py-2 text-left text-[13px]', danger ? 'border-[#f0b8a6] bg-[#fff4f0] text-[#7a2200]' : 'border-border bg-[#f2f4f8]')}>{options.note}</div>
                            )}
                        </div>

                        <div className="flex gap-2 px-6 pb-6 pt-4">
                            <Button type="button" variant="ghost" data-cancel className="h-10 flex-1 border border-border" onClick={onClose}>
                                {options.cancelLabel ?? 'Cancel'}
                            </Button>
                            <Button
                                type="button"
                                variant={danger ? 'default' : 'secondary'}
                                className={cn('h-10 flex-1', danger && 'bg-destructive text-white hover:bg-destructive/90')}
                                onClick={() => {
                                    const run = options.onConfirm;
                                    onClose();
                                    run();
                                }}
                            >
                                {options.confirmLabel ?? 'Confirm'}
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * const [confirm, confirmDialog] = useConfirm();
 * confirm({ title: 'Delete it?', tone: 'danger', confirmLabel: 'Delete', onConfirm: () => ... });
 * ...render {confirmDialog} once in the page.
 */
export function useConfirm(): [(o: ConfirmOptions) => void, ReactNode] {
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const ask = useCallback((o: ConfirmOptions) => setOptions(o), []);
    const dialog = <ConfirmDialog options={options} onClose={() => setOptions(null)} />;

    return [ask, dialog];
}
