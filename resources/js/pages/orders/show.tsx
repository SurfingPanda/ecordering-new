import { useCallback, useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Ban, Check, CloudUpload, Loader2, RefreshCw, RotateCcw, StickyNote } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import { StatusBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogUnitsKey, catalogs, nf } from '@/lib/charts';
import { formatDate, formatDay } from '@/lib/format';
import { panel, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource, OrderSummary } from '@/types';

interface Props {
    order: OrderSummary & {
        notes: string | null;
        posted_at: string | null;
        ecpos: { journal_id: string | null; sent_at: string; note: string | null } | null;
        cancelled_at: string | null;
        cancelled_by: string | null;
        cancellation_reason: string | null;
        lines: { id: number; product_code: string; name: string; source: ItemSource; category: string | null; quantity: number }[];
        events: { id: number; event: 'placed' | 'posted' | 'cancelled' | 'reset' | 'sent'; note: string | null; by: string | null; at: string }[];
    };
    can: { post: boolean; reset: boolean; cancel: boolean; cancel_needs_reason: boolean; send_ecpos: boolean };
}

const eventLabel = { placed: 'Placed', posted: 'Posted', cancelled: 'Cancelled', reset: 'Reset for editing', sent: 'Sent to ECPOS' } as const;
const eventDot = { placed: 'bg-muted-foreground', posted: 'bg-[#16683a]', cancelled: 'bg-destructive', reset: 'bg-[#c27a00]', sent: 'bg-secondary' } as const;

/**
 * Reading order on this page:
 *  1. who/what this is (TR number + status) and what you can do about it (actions)
 *  2. how much was ordered (the three quantity cards)
 *  3. exactly what was ordered (items, grouped by catalog)
 *  4. supporting facts (details, notes, history) in a quieter side column
 */
export default function ShowOrder({ order, can }: Props) {
    const [cancelling, setCancelling] = useState(false);
    const [resetError, setResetError] = useState<string | null>(null);
    const [confirm, confirmDialog] = useConfirm();

    const post = () =>
        confirm({
            title: `Post order ${order.number}?`,
            description: 'Posting submits the order. Once posted it can no longer be changed back to pending.',
            confirmLabel: 'Post order',
            onConfirm: () =>
                router.post(
                    `/orders/${order.id}/post`,
                    {},
                    {
                        preserveScroll: true,
                        onError: (e) =>
                            void Swal.fire({
                                icon: 'error',
                                title: 'Couldn’t post the order',
                                text: `${e.ecpos ?? e.deadline ?? 'Something went wrong.'} The order is still pending, so you can try again.`,
                                confirmButtonText: 'OK',
                                showCloseButton: true,
                                allowOutsideClick: false,
                                customClass: { confirmButton: 'swal-confirm' },
                            }),
                    },
                ),
        });

    const sendToEcpos = () =>
        confirm({
            title: `Send ${order.number} to ECPOS?`,
            description: 'This order is posted but ECPOS doesn’t have it yet. It will be sent now, once.',
            confirmLabel: 'Send to ECPOS',
            onConfirm: () =>
                router.post(
                    `/orders/${order.id}/ecpos-send`,
                    {},
                    {
                        preserveScroll: true,
                        onError: (e) =>
                            void Swal.fire({
                                icon: 'error',
                                title: 'Couldn’t send to ECPOS',
                                text: e.ecpos ?? 'Something went wrong. Nothing was sent.',
                                confirmButtonText: 'OK',
                                showCloseButton: true,
                                allowOutsideClick: false,
                                customClass: { confirmButton: 'swal-confirm' },
                            }),
                    },
                ),
        });

    const reset = () =>
        confirm({
            title: `Reset order ${order.number}?`,
            description: 'The order goes back to a draft so you can add or change items, then place it again. It keeps the same TR number and won’t count until you place it again.',
            confirmLabel: 'Reset and edit',
            onConfirm: () => {
                setResetError(null);
                router.post(`/orders/${order.id}/reset`, {}, { onError: (e) => setResetError(e.reset ?? e.deadline ?? 'This order can’t be reset right now.') });
            },
        });

    // items grouped by catalog, BW Products first, each with its own subtotal
    const groups = catalogs
        .map((source) => {
            const rows = order.lines.filter((l) => l.source === source);
            return { source, rows, units: rows.reduce((s, l) => s + l.quantity, 0) };
        })
        .filter((g) => g.rows.length > 0);

    const cancelled = order.status === 'cancelled';

    return (
        <AppLayout title="Order details">
            <Link href="/orders" className="mb-3 inline-flex items-center gap-1 text-[13px] font-medium text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-3.5" /> All orders
            </Link>

            {/* 1 — identity and actions */}
            <header className={cn(panel, 'mb-5 flex flex-wrap items-start justify-between gap-4 p-5')}>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className={cn('font-mono text-2xl font-semibold tracking-wide', cancelled && 'text-muted-foreground line-through decoration-1')}>{order.number}</h2>
                        <StatusBadge status={order.status} className="px-2.5 py-1 text-[13px]" />
                    </div>
                    <p className="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-muted-foreground">
                        {order.store && (
                            <>
                                <span className="font-semibold text-foreground">
                                    {order.store.code} — {order.store.name}
                                </span>
                                <span aria-hidden="true">·</span>
                            </>
                        )}
                        <span>
                            Order date <span className="font-medium text-foreground">{formatDay(order.order_date)}</span>
                        </span>
                        <span aria-hidden="true">·</span>
                        <span>
                            Ordered by <span className="font-medium text-foreground">{order.ordered_by}</span>
                        </span>
                    </p>
                </div>

                {(can.post || can.reset || can.cancel || can.send_ecpos) && (
                    <div className="flex flex-wrap gap-2">
                        {can.cancel && (
                            <Button variant="ghost" className="border border-border text-destructive hover:bg-destructive/5 hover:text-destructive" onClick={() => setCancelling(true)}>
                                <Ban /> Cancel order
                            </Button>
                        )}
                        {can.send_ecpos && (
                            <Button variant="ghost" className="border border-secondary text-secondary hover:bg-accent" onClick={sendToEcpos}>
                                <CloudUpload /> Send to ECPOS
                            </Button>
                        )}
                        {can.reset && (
                            <Button variant="ghost" className="border border-border" onClick={reset}>
                                <RotateCcw /> Reset order
                            </Button>
                        )}
                        {can.post && (
                            <Button variant="secondary" onClick={post}>
                                <Check /> Post order
                            </Button>
                        )}
                    </div>
                )}
            </header>

            {order.ecpos && <EcposBanner orderId={order.id} ecpos={order.ecpos} />}

            {resetError && (
                <p role="alert" className="mb-5 rounded-md border border-[#f0b8a6] bg-[#ffece6] px-4 py-3 text-sm text-[#9c2a00]">
                    {resetError}
                </p>
            )}

            {cancelled && (
                <div role="status" className="mb-5 flex items-start gap-3 rounded-md border border-[#e3c5bb] bg-[#fff6f3] px-4 py-3 text-sm">
                    <Ban className="mt-0.5 size-4 shrink-0 text-destructive" />
                    <div>
                        <p className="font-semibold text-[#7a2200]">
                            This TR was cancelled{order.cancelled_by && <> by {order.cancelled_by}</>}
                            {order.cancelled_at && <> on {formatDate(order.cancelled_at)}</>}
                        </p>
                        {order.cancellation_reason && <p className="mt-0.5">“{order.cancellation_reason}”</p>}
                        <p className="mt-0.5 text-xs text-muted-foreground">The original items are kept below for the record.</p>
                    </div>
                </div>
            )}

            {/* 2 — quantities */}
            <div className="mb-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
                <div className={cn(panel, 'col-span-2 bg-gradient-to-br from-[#f1f4fe] to-[#dbe4fc] px-5 py-4 lg:col-span-1', cancelled && 'opacity-60')}>
                    <p className="text-[13px] font-medium text-foreground/70">Total units</p>
                    <p className="mt-1 text-3xl font-semibold tabular-nums">{nf.format(order.units)}</p>
                    <p className="mt-1 text-xs text-foreground/70">
                        {order.lines.length} {order.lines.length === 1 ? 'item' : 'items'} ordered
                    </p>
                </div>
                {catalogs.map((s) => (
                    <UnitsCard key={s} source={s} units={order[catalogUnitsKey[s]]} muted={cancelled} />
                ))}
            </div>

            <div className="grid items-start gap-5 lg:grid-cols-[1fr_340px]">
                {/* 3 — the items */}
                <section className={cn(panel, 'overflow-hidden', cancelled && 'opacity-75')}>
                    <div className="flex items-baseline justify-between border-b border-border px-4 py-3">
                        <h2 className="text-sm font-semibold">Items ordered</h2>
                        <span className="text-xs text-muted-foreground">{order.lines.length} lines</span>
                    </div>
                    {/* the page itself doesn't scroll: a long list scrolls inside this box (scroll bar hidden) */}
                    <div className="no-scrollbar max-h-[calc(100vh-28rem)] min-h-[220px] overflow-auto">
                        <table className="w-full border-collapse sm:min-w-[460px]">
                            <thead className="sticky top-0 z-10 bg-secondary text-secondary-foreground">
                                <tr>
                                    <th className={th}>Product code</th>
                                    <th className={th}>Description</th>
                                    <th className={cn(th, 'max-sm:hidden')}>Category</th>
                                    <th className={cn(th, 'text-right')}>Qty</th>
                                </tr>
                            </thead>
                            {groups.map((g) => (
                                <tbody key={g.source}>
                                    <tr className="border-b border-border bg-[#f2f4f8]">
                                        <td colSpan={3} className="px-3 py-2 text-xs font-semibold uppercase tracking-wide">
                                            <span className="inline-flex items-center gap-2">
                                                <span className="size-2.5 rounded-[3px]" style={{ background: catalogColor[g.source] }} />
                                                {catalogLabel[g.source]}
                                                <span className="font-normal normal-case tracking-normal text-muted-foreground">
                                                    · {g.rows.length} {g.rows.length === 1 ? 'item' : 'items'}
                                                </span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-right text-xs font-semibold tabular-nums">{nf.format(g.units)}</td>
                                    </tr>
                                    {g.rows.map((l) => (
                                        <tr key={l.id} className="border-b border-border/70 last:border-b-0">
                                            <td className={cn(td, 'whitespace-nowrap font-mono text-muted-foreground')}>{l.product_code}</td>
                                            <td className={cn(td, 'font-medium')}>{l.name}</td>
                                            <td className={cn(td, 'whitespace-nowrap text-muted-foreground max-sm:hidden')}>{l.category ?? '—'}</td>
                                            <td className={cn(td, 'text-right text-sm font-semibold tabular-nums')}>{nf.format(l.quantity)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            ))}
                            <tfoot className="sticky bottom-0">
                                <tr className="border-t-2 border-border bg-[#f8f9fc]">
                                    <td colSpan={3} className={cn(td, 'py-3 text-right font-semibold')}>
                                        Total units
                                    </td>
                                    <td className="px-3 py-3 text-right text-base font-semibold tabular-nums">{nf.format(order.units)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                {/* 4 — supporting information */}
                <aside className="space-y-5 lg:sticky lg:top-20">
                    {order.notes && (
                        <section className={panel}>
                            <h2 className="flex items-center gap-1.5 border-b border-border px-4 py-3 text-sm font-semibold">
                                <StickyNote className="size-3.5 text-muted-foreground" /> Notes from the store
                            </h2>
                            <p className="m-4 whitespace-pre-line rounded-md bg-[#fff8e6] px-3 py-2 text-sm">{order.notes}</p>
                        </section>
                    )}

                    <section className={panel}>
                        <h2 className="border-b border-border px-4 py-3 text-sm font-semibold">History</h2>
                        {order.events.length === 0 ? (
                            <p className="px-4 py-5 text-sm text-muted-foreground">No history recorded.</p>
                        ) : (
                            <ol className="p-4">
                                {order.events.map((e, i) => (
                                    <li key={e.id} className="relative flex gap-3 pb-4 last:pb-0">
                                        {i < order.events.length - 1 && <span className="absolute left-[5px] top-3 h-full w-px bg-border" aria-hidden="true" />}
                                        <span className={cn('relative mt-1 size-[11px] shrink-0 rounded-full ring-4 ring-white', eventDot[e.event])} />
                                        <div className="min-w-0 flex-1 text-[13px]">
                                            <p>
                                                <span className="font-semibold">{eventLabel[e.event]}</span>
                                                {e.by && <span className="text-muted-foreground"> by {e.by}</span>}
                                            </p>
                                            <time className="text-xs text-muted-foreground">{formatDate(e.at)}</time>
                                            {e.note && <p className="mt-1 rounded bg-[#f2f4f8] px-2 py-1 text-xs">“{e.note}”</p>}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                </aside>
            </div>

            {confirmDialog}
            <CancelDialog key={cancelling ? 'open' : 'closed'} open={cancelling} order={order} needsReason={can.cancel_needs_reason} onClose={() => setCancelling(false)} />
        </AppLayout>
    );
}

interface EcposInfo {
    status: string | null;
    store: string | null;
    received_at: string | null;
    items: number;
    units: number;
}

/** "Sent to ECPOS · journal …" plus ECPOS's own status for the order, looked up live (with a refresh button). */
function EcposBanner({ orderId, ecpos }: { orderId: number; ecpos: { journal_id: string | null; sent_at: string; note: string | null } }) {
    const [info, setInfo] = useState<EcposInfo | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const check = useCallback(async () => {
        if (!ecpos.journal_id) return;
        setLoading(true);
        setError(null);
        try {
            const res = await fetch(`/orders/${orderId}/ecpos-status`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body.message ?? 'Couldn’t check ECPOS right now.');
            setInfo(body);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Couldn’t check ECPOS right now.');
        } finally {
            setLoading(false);
        }
    }, [orderId, ecpos.journal_id]);

    useEffect(() => {
        void check();
    }, [check]);

    const status = info?.status ?? null;
    const tone = !status ? '' : /cancel|reject|fail|void/i.test(status) ? 'bg-[#ffe9e2] text-[#9c2a00]' : /pend|wait|new|open/i.test(status) ? 'bg-[#fff1d6] text-[#8a5200]' : 'bg-[#e3f5ea] text-[#16683a]';

    return (
        <div role="status" className="mb-5 rounded-md border border-[#c9d4fa] bg-[#eef2ff] px-4 py-3 text-sm text-[#1f3aa8]">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <p className="font-semibold">
                    Sent to ECPOS{ecpos.journal_id && <> · journal <span className="font-mono">{ecpos.journal_id}</span></>} · {formatDate(ecpos.sent_at)}
                </p>
                {ecpos.journal_id && (
                    <span className="ml-auto inline-flex items-center gap-2">
                        {status && (
                            <span className={cn('rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide', tone)} title="ECPOS’s status for this order">
                                ECPOS: {status}
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={check}
                            disabled={loading}
                            title="Check ECPOS again"
                            aria-label="Check the ECPOS status again"
                            className="grid size-8 place-items-center rounded-md border border-[#c9d4fa] bg-white text-[#1f3aa8] hover:bg-white/70 disabled:opacity-60"
                        >
                            {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                        </button>
                    </span>
                )}
            </div>
            {info && (
                <p className="mt-1 text-xs text-[#1f3aa8]/80">
                    ECPOS has {info.items} {info.items === 1 ? 'item' : 'items'} ({info.units} units){info.store ? ` for ${info.store}` : ''}
                    {info.received_at ? `, received ${info.received_at}` : ''}.
                </p>
            )}
            {error && <p className="mt-1 text-xs font-medium text-[#9c2a00]">{error}</p>}
            {!ecpos.journal_id && <p className="mt-1 text-xs">ECPOS accepted the order but didn’t give a journal number, so its status can’t be looked up.</p>}
            {ecpos.note && <p className="mt-1 text-xs">{ecpos.note}</p>}
        </div>
    );
}

function UnitsCard({ source, units, muted }: { source: ItemSource; units: number; muted: boolean }) {
    return (
        <div className={cn(panel, 'px-5 py-4', muted && 'opacity-60')}>
            <p className="flex items-center gap-2 text-[13px] font-medium text-muted-foreground">
                <span className="size-2.5 rounded-[3px]" style={{ background: catalogColor[source] }} />
                {catalogLabel[source]}
            </p>
            <p className={cn('mt-1 text-3xl font-semibold tabular-nums', units === 0 && 'text-muted-foreground/50')}>{units > 0 ? nf.format(units) : '–'}</p>
            <p className="mt-1 text-xs text-muted-foreground">{units > 0 ? 'units' : 'nothing ordered'}</p>
        </div>
    );
}

/** Confirmation prompt: shows which TR is being cancelled and (for admins) requires a reason. */
function CancelDialog({ open, order, needsReason, onClose }: { open: boolean; order: Props['order']; needsReason: boolean; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/orders/${order.id}/cancel`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Cancel {order.number}?</DialogTitle>
                <DialogDescription>
                    {needsReason
                        ? 'The TR stays on record as Cancelled and its items are kept. The store will be able to place a new order for that date.'
                        : ''}
                </DialogDescription>

                <dl className="mt-4 grid grid-cols-[110px_1fr] gap-x-3 gap-y-1.5 rounded-md border border-border bg-[#f2f4f8] p-3 text-[13px]">
                    <dt className="text-muted-foreground">TR number</dt>
                    <dd className="font-mono font-medium">{order.number}</dd>
                    {order.store && (
                        <>
                            <dt className="text-muted-foreground">Store</dt>
                            <dd className="font-medium">
                                {order.store.code} — {order.store.name}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Order date</dt>
                    <dd className="font-medium">{formatDay(order.order_date)}</dd>
                    <dt className="text-muted-foreground">Status</dt>
                    <dd>
                        <StatusBadge status={order.status} />
                    </dd>
                </dl>

                <form onSubmit={submit} noValidate className="mt-4 space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="reason" className="text-[13px]">
                            Reason {needsReason ? '(required)' : '(optional)'}
                        </Label>
                        <textarea
                            id="reason"
                            rows={3}
                            autoFocus
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            aria-invalid={!!form.errors.reason}
                            placeholder={needsReason ? 'e.g. Quantities were entered wrongly' : ''}
                            className={cn(
                                'w-full rounded-md border bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20',
                                form.errors.reason ? 'border-destructive' : 'border-input',
                            )}
                        />
                        {form.errors.reason && <p className="text-xs font-medium text-destructive">{form.errors.reason}</p>}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Keep order
                        </Button>
                        <Button type="submit" size="sm" className="bg-destructive hover:bg-destructive/90" disabled={form.processing}>
                            {form.processing ? 'Cancelling…' : 'Cancel order'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
