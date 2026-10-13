import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CloudOff, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { draftBackupKey, sendQueuedOrder, useQueuedOrder, writeQueued } from '@/lib/queued-order';

const RETRY_EVERY = 20_000;

/**
 * Shown on every page while an order is waiting to be placed: says plainly that it is NOT sent yet, retries by itself
 * (when the connection returns, when the app is opened again, and every 20 seconds), and opens the order once it went through.
 */
export default function QueuedOrderSync({ userId }: { userId: number }) {
    const q = useQueuedOrder(userId);
    const [sending, setSending] = useState(false);
    const busy = useRef(false);

    const attempt = useCallback(async () => {
        if (busy.current) return;
        const current = q;
        if (!current || current.rejected || current.signedOut) return;

        busy.current = true;
        setSending(true);
        const result = await sendQueuedOrder(current);
        busy.current = false;
        setSending(false);

        if (result.kind === 'sent') {
            try {
                localStorage.removeItem(draftBackupKey(current.orderId));
            } catch {
                /* nothing to clean */
            }
            writeQueued(userId, null);
            router.visit(result.url);
        } else if (result.kind === 'rejected') {
            writeQueued(userId, { ...current, rejected: result.message });
        } else if (result.kind === 'signed_out') {
            writeQueued(userId, { ...current, signedOut: true });
        }
    }, [q, userId]);

    const queuedId = q?.orderId;
    const waiting = q !== null && !q.rejected && !q.signedOut;
    useEffect(() => {
        if (!waiting) return;
        void attempt(); // right away on every page load, and as soon as the order is queued
        const up = () => void attempt();
        const visible = () => document.visibilityState === 'visible' && void attempt();
        const timer = setInterval(() => void attempt(), RETRY_EVERY);
        window.addEventListener('online', up);
        document.addEventListener('visibilitychange', visible);

        return () => {
            clearInterval(timer);
            window.removeEventListener('online', up);
            document.removeEventListener('visibilitychange', visible);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [waiting, queuedId]);

    if (!q) return null;

    if (q.rejected) {
        return (
            <div role="alert" className="mb-4 flex flex-wrap items-start gap-3 rounded-md border border-[#f0b8a6] bg-[#ffece6] px-4 py-3 text-sm text-[#9c2a00]">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                <p className="min-w-0 flex-1">
                    <span className="font-semibold">Order {q.number} was not placed.</span> {q.rejected}
                </p>
                <div className="flex gap-2">
                    <Button asChild size="sm" variant="secondary">
                        <Link href="/orders/create">Review order</Link>
                    </Button>
                    <Button size="sm" variant="ghost" className="border border-[#f0b8a6]" onClick={() => writeQueued(userId, null)}>
                        Dismiss
                    </Button>
                </div>
            </div>
        );
    }

    if (q.signedOut) {
        return (
            <div role="alert" className="mb-4 flex flex-wrap items-start gap-3 rounded-md border border-[#f0c58a] bg-[#fff6e5] px-4 py-3 text-sm text-[#7a4a00]">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                <p className="min-w-0 flex-1">
                    <span className="font-semibold">Order {q.number} is not sent yet.</span> You were signed out. Sign in again and it will be placed.
                </p>
                <Button asChild size="sm" variant="secondary">
                    <a href="/login">Sign in</a>
                </Button>
            </div>
        );
    }

    return (
        <div role="status" className="mb-4 flex flex-wrap items-start gap-3 rounded-md border border-[#f0c58a] bg-[#fff6e5] px-4 py-3 text-sm text-[#7a4a00]">
            {sending ? <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin" /> : <CloudOff className="mt-0.5 size-4 shrink-0" />}
            <p className="min-w-0 flex-1">
                <span className="font-semibold">Order {q.number} is not sent yet.</span>{' '}
                {sending ? 'Sending it now…' : 'It is saved on this device and will be placed automatically as soon as you are back online. Keep this app open.'}
            </p>
            <div className="flex gap-2">
                <Button size="sm" variant="secondary" disabled={sending} onClick={() => void attempt()}>
                    Send now
                </Button>
                <Button size="sm" variant="ghost" className="border border-[#f0c58a]" disabled={sending} onClick={() => writeQueued(userId, null)}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}
