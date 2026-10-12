import { Loader2 } from 'lucide-react';

import { cn } from '@/lib/utils';

/** A small flag beside an order's status when its trip to ECPOS isn't finished or went wrong (nothing for a normal, sent order). */
export default function EcposTag({ state, className }: { state: 'queued' | 'sending' | 'sent' | 'failed' | null | undefined; className?: string }) {
    if (state === 'failed') {
        return <span className={cn('ml-1.5 inline-block rounded bg-[#ffe9e2] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#9c2a00]', className)}>ECPOS failed</span>;
    }
    if (state === 'queued' || state === 'sending') {
        return (
            <span className={cn('ml-1.5 inline-flex items-center gap-1 rounded bg-[#e8ecfb] px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#1f3aa8]', className)}>
                <Loader2 className="size-2.5 animate-spin" /> Sending
            </span>
        );
    }

    return null;
}
