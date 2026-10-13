import { useEffect, useState } from 'react';

/**
 * A "Place order" made without a connection (or one that never got an answer) is kept on this device and sent again
 * until the server answers. The server treats a repeat of an order it already placed as that same order, so retrying
 * can never place it twice. One queued order per signed-in person.
 */
export interface QueuedOrder {
    orderId: number;
    number: string;
    order_date: string;
    notes: string;
    staff_name: string;
    lines: { item_id: number; quantity: number }[];
    queuedAt: string;
    /** why the server refused it (deadline passed, below the minimum...): it is not retried, the person has to look at it */
    rejected?: string;
    /** the session ran out: sign in again, then it is sent */
    signedOut?: boolean;
}

export type SendResult = { kind: 'sent'; url: string } | { kind: 'retry' } | { kind: 'signed_out' } | { kind: 'rejected'; message: string; errors: Record<string, string> };

const EVENT = 'bw-queued-order';
const key = (userId: number) => `bw-queued-order-${userId}`;
export const draftBackupKey = (orderId: number) => `bw-draft-${orderId}`;

export function readQueued(userId: number): QueuedOrder | null {
    try {
        const raw = localStorage.getItem(key(userId));

        return raw ? (JSON.parse(raw) as QueuedOrder) : null;
    } catch {
        return null;
    }
}

export function writeQueued(userId: number, value: QueuedOrder | null): void {
    try {
        if (value) localStorage.setItem(key(userId), JSON.stringify(value));
        else localStorage.removeItem(key(userId));
    } catch {
        /* storage unavailable: nothing can be kept, the page just stays online-only */
    }
    window.dispatchEvent(new Event(EVENT));
}

/** The queued order of this person, kept up to date across components and browser tabs. */
export function useQueuedOrder(userId: number): QueuedOrder | null {
    const [value, setValue] = useState<QueuedOrder | null>(() => readQueued(userId));
    useEffect(() => {
        const sync = () => setValue(readQueued(userId));
        sync();
        window.addEventListener(EVENT, sync);
        window.addEventListener('storage', sync);

        return () => {
            window.removeEventListener(EVENT, sync);
            window.removeEventListener('storage', sync);
        };
    }, [userId]);

    return value;
}

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/** One attempt to place the order. Never throws: a network problem is just `retry`. */
export async function sendQueuedOrder(q: QueuedOrder): Promise<SendResult> {
    let res: Response;
    try {
        res = await fetch('/orders', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ order_id: q.orderId, order_date: q.order_date, notes: q.notes, staff_name: q.staff_name || undefined, lines: q.lines }),
        });
    } catch {
        return { kind: 'retry' }; // offline, or the connection dropped
    }

    if (res.ok && res.redirected) return { kind: 'sent', url: res.url };
    if (res.status === 419 || res.status === 401) return { kind: 'signed_out' };
    if (res.status === 422 || res.status === 403 || res.status === 409) {
        const body = (await res.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
        const errors = Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, v]) => [k, v[0]]));

        return { kind: 'rejected', message: Object.values(errors)[0] ?? body.message ?? 'The order could not be placed.', errors };
    }

    return { kind: 'retry' }; // 5xx, 429, a gateway page: try again later
}
