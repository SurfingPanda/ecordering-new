import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, CloudUpload, Loader2, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/format';
import { panel, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';

type Kind = 'failed' | 'stuck' | 'sending' | 'not_sent';

interface Row {
    id: number;
    number: string;
    store: string | null;
    order_date: string | null;
    posted_at: string | null;
    units: number;
    kind: Kind;
    attempts: number;
    error: string | null;
    uncertain: boolean;
    since: string | null;
    next_at: string | null;
}

interface Props {
    orders: Row[];
    sending_enabled: boolean;
    scheduler: { stale: boolean; last_beat: string | null };
    counts: Partial<Record<Kind, number>>;
    safe_to_retry: number;
}

const kindLabel: Record<Kind, { text: string; cls: string }> = {
    failed: { text: 'Failed', cls: 'bg-[#ffe9e2] text-[#9c2a00]' },
    stuck: { text: 'Stuck in queue', cls: 'bg-[#fff1d6] text-[#8a5200]' },
    sending: { text: 'Sending', cls: 'bg-[#e8ecfb] text-[#1f3aa8]' },
    not_sent: { text: 'Never sent', cls: 'bg-muted text-muted-foreground' },
};

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

/** "ECPOS Queue": every posted order that has not reached ECPOS, why, and a safe way to send them again. */
export default function EcposQueue({ orders, sending_enabled, scheduler, counts, safe_to_retry }: Props) {
    const [busy, setBusy] = useState(false);
    const [confirm, confirmDialog] = useConfirm();

    const refresh = () => router.reload({ only: ['orders', 'counts', 'safe_to_retry', 'scheduler'] });
    const retryAll = () =>
        confirm({
            title: `Send ${safe_to_retry} ${safe_to_retry === 1 ? 'order' : 'orders'} to ECPOS again?`,
            description: 'Only orders ECPOS definitely does not have are sent. Ones that may already be in ECPOS, and ones still in the queue, are left for you to check one by one.',
            confirmLabel: 'Send again',
            onConfirm: () => router.post('/admin/ecpos-queue/retry', {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) }),
        });

    return (
        <AppLayout
            title="ECPOS Queue"
            actions={
                <>
                    <Button size="sm" variant="ghost" className="border border-border bg-white" onClick={refresh}>
                        <RefreshCw /> <span className="max-sm:sr-only">Refresh</span>
                    </Button>
                    {sending_enabled && (
                        <Button size="sm" variant="secondary" onClick={retryAll} disabled={busy || safe_to_retry === 0}>
                            {busy ? <Loader2 className="animate-spin" /> : <CloudUpload />} Retry all ({safe_to_retry})
                        </Button>
                    )}
                </>
            }
        >
            {confirmDialog}

            {scheduler.stale && (
                <div role="alert" className="mb-4 flex items-start gap-2 rounded-md border border-[#f0c58a] bg-[#fff6e5] px-4 py-3 text-sm text-[#7a4a00]">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <p>
                        <strong>The scheduler is not running</strong>
                        {scheduler.last_beat ? ` (last seen ${when(scheduler.last_beat)})` : ' (it has never been seen)'}. Orders are not being sent to ECPOS until it is started again (see “Scheduler” in the README).
                    </p>
                </div>
            )}

            {!sending_enabled && (
                <p className="mb-4 rounded-md border border-border bg-muted px-4 py-3 text-sm text-muted-foreground">Sending orders to ECPOS is switched off, so only orders that already started a send are listed.</p>
            )}

            <section className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {(Object.keys(kindLabel) as Kind[]).map((k) => (
                    <div key={k} className={cn(panel, 'px-4 py-3')}>
                        <p className="text-xs font-medium text-muted-foreground">{kindLabel[k].text}</p>
                        <p className={cn('mt-0.5 text-2xl font-semibold tabular-nums', (counts[k] ?? 0) > 0 && (k === 'failed' || k === 'stuck') && 'text-[#c25a00]')}>{counts[k] ?? 0}</p>
                    </div>
                ))}
            </section>

            {orders.length === 0 ? (
                <div className={cn(panel, 'flex flex-col items-center gap-2 px-4 py-14 text-center')}>
                    <CheckCircle2 className="size-8 text-[#16a34a]" />
                    <p className="font-semibold">Every posted order has reached ECPOS.</p>
                </div>
            ) : (
                <div className={cn(panel, 'overflow-x-auto')}>
                    <table className="w-full min-w-[760px]">
                        <thead className="border-b border-border bg-muted/60">
                            <tr>
                                <th className={th}>Order</th>
                                <th className={th}>Store</th>
                                <th className={th}>Status</th>
                                <th className={th}>Tries</th>
                                <th className={th}>Since</th>
                                <th className={th}>Reason</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {orders.map((o) => (
                                <tr key={o.id} className="align-top">
                                    <td className={td}>
                                        <Link href={`/orders/${o.id}`} className="font-semibold text-secondary hover:underline">
                                            {o.number}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {o.order_date ? formatDate(o.order_date) : '—'} · {o.units} units
                                        </div>
                                    </td>
                                    <td className={td}>{o.store ?? '—'}</td>
                                    <td className={td}>
                                        <span className={cn('rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide', kindLabel[o.kind].cls)}>{kindLabel[o.kind].text}</span>
                                        {o.uncertain && <div className="mt-1 text-xs font-medium text-[#9c2a00]">May already be in ECPOS — check before sending again</div>}
                                    </td>
                                    <td className={cn(td, 'tabular-nums')}>{o.attempts}</td>
                                    <td className={td}>
                                        {when(o.since ?? o.posted_at)}
                                        {o.next_at && <div className="text-xs text-muted-foreground">retry {when(o.next_at)}</div>}
                                    </td>
                                    <td className={cn(td, 'max-w-xs text-xs text-muted-foreground')}>{o.error ?? (o.kind === 'not_sent' ? 'Posted but never sent to ECPOS.' : '—')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
