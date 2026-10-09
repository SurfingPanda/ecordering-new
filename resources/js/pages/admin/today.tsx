import { useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock, Loader2, RefreshCw, Search, X } from 'lucide-react';

import { StatusBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { nf } from '@/lib/charts';
import { formatDay, formatTime } from '@/lib/format';
import { field, panel } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { OrderStatus } from '@/types';

interface StoreBase {
    id: number;
    code: string;
    name: string;
    ecpos_store_id: string | null;
}

interface Waiting extends StoreBase {
    deadline_time: string;
    deadline_at: string;
    has_login: boolean;
    in_progress: boolean;
}

interface Ordered extends StoreBase {
    order: { id: number; number: string; status: OrderStatus; units: number; lines: number; by: string | null; at: string };
}

interface Props {
    date: string;
    is_today: boolean;
    summary: { total: number; ordered: number; waiting: number; no_login: number };
    waiting: Waiting[];
    ordered: Ordered[];
}

/** The lists scroll inside their own panels (scroll bar hidden) so the page itself stays on one screen. */
const listHeight = 'no-scrollbar max-h-[calc(100svh-21rem)] min-h-[16rem] overflow-y-auto';

/** "Today's Orders": who has placed their order for the day and who is still to go, grouped by cutoff time. */
export default function Today({ date, is_today, summary, waiting, ordered }: Props) {
    const [now, setNow] = useState(() => Date.now());
    const [refreshing, setRefreshing] = useState(false);
    const [query, setQuery] = useState('');
    const [tab, setTab] = useState<'waiting' | 'ordered'>('waiting'); // phones show one list at a time

    // keep the page fresh while it's open: countdowns tick every 30 s and the lists reload every minute
    useEffect(() => {
        const tick = setInterval(() => setNow(Date.now()), 30_000);
        const reload = setInterval(() => router.reload({ only: ['summary', 'waiting', 'ordered'] }), 60_000);
        return () => {
            clearInterval(tick);
            clearInterval(reload);
        };
    }, []);

    const refresh = () => {
        setRefreshing(true);
        router.reload({ only: ['summary', 'waiting', 'ordered'], onFinish: () => setRefreshing(false) });
    };

    const q = query.trim().toLowerCase();
    const match = (s: StoreBase) => q === '' || `${s.name} ${s.code} ${s.ecpos_store_id ?? ''}`.toLowerCase().includes(q);

    const minutesLeft = (at: string) => Math.ceil((new Date(at).getTime() - now) / 60_000);
    const leftText = (at: string) => {
        if (!is_today) return { text: 'closed', tone: 'text-muted-foreground' };
        const m = minutesLeft(at);
        if (m <= 0) return { text: 'past the cutoff', tone: 'font-semibold text-destructive' };
        return { text: m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m left` : `${m}m left`, tone: m <= 30 ? 'font-semibold text-[#9a5b00]' : 'text-muted-foreground' };
    };

    // most stores share one cutoff, so list them under it instead of repeating the time on every row
    const groups = useMemo(() => {
        const map = new Map<string, { time: string; at: string; stores: Waiting[] }>();
        for (const s of waiting.filter(match)) {
            const g = map.get(s.deadline_at) ?? { time: s.deadline_time, at: s.deadline_at, stores: [] };
            g.stores.push(s);
            map.set(s.deadline_at, g);
        }
        return [...map.values()].sort((a, b) => a.at.localeCompare(b.at));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [waiting, q]);

    const shownOrdered = ordered.filter(match);
    const started = waiting.filter((s) => s.in_progress).length;
    const pct = summary.total > 0 ? Math.round((summary.ordered / summary.total) * 100) : 0;
    const next = waiting.length > 0 ? waiting.map((w) => w.deadline_at).sort()[0] : null;
    const nextLeft = next ? leftText(next) : null;

    return (
        <AppLayout
            title="Today’s Orders"
            actions={
                <>
                    <Input
                        type="date"
                        aria-label="Date"
                        value={date}
                        onChange={(e) => e.target.value && router.get('/admin/today', { date: e.target.value }, { preserveState: true, replace: true })}
                        className={cn(field, 'hidden w-40 sm:block')}
                    />
                    {!is_today && (
                        <Button size="sm" variant="ghost" className="border border-border bg-white" onClick={() => router.get('/admin/today')}>
                            Today
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" className="border border-border bg-white" onClick={refresh} disabled={refreshing}>
                        {refreshing ? <Loader2 className="animate-spin" /> : <RefreshCw />} <span className="max-sm:sr-only">Refresh</span>
                    </Button>
                </>
            }
        >
            {/* the numbers that matter, in one row */}
            <section className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div className={cn(panel, 'col-span-2 px-4 py-3 lg:col-span-1')}>
                    <p className="text-xs font-medium text-muted-foreground">{is_today ? 'Today' : formatDay(date)} · stores ordered</p>
                    <p className="mt-0.5 text-2xl font-semibold tabular-nums">
                        {summary.ordered} <span className="text-base font-normal text-muted-foreground">/ {summary.total}</span>
                    </p>
                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-[#e5e9f7]" role="img" aria-label={`${pct}% of stores have ordered`}>
                        <div className="h-full rounded-full bg-[#16a34a] transition-all" style={{ width: `${pct}%` }} />
                    </div>
                </div>
                <div className={cn(panel, 'px-4 py-3')}>
                    <p className="text-xs font-medium text-muted-foreground">Still to go</p>
                    <p className={cn('mt-0.5 text-2xl font-semibold tabular-nums', summary.waiting > 0 && 'text-[#c25a00]')}>{summary.waiting}</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {started > 0 ? `${started} started an order` : 'none started yet'}
                        {summary.no_login > 0 && <span className="text-[#9c2a00]"> · {summary.no_login} no login</span>}
                    </p>
                </div>
                <div className={cn(panel, 'px-4 py-3')}>
                    <p className="text-xs font-medium text-muted-foreground">{is_today ? 'Next cutoff' : 'Cutoff'}</p>
                    <p className="mt-0.5 text-2xl font-semibold tabular-nums">{next ? formatTime(waiting.find((w) => w.deadline_at === next)!.deadline_time) : '—'}</p>
                    <p className={cn('mt-1 text-xs', nextLeft?.tone)}>{nextLeft ? nextLeft.text : 'nothing waiting'}</p>
                </div>
                <div className={cn(panel, 'hidden px-4 py-3 lg:block')}>
                    <p className="text-xs font-medium text-muted-foreground">Units ordered</p>
                    <p className="mt-0.5 text-2xl font-semibold tabular-nums">{nf.format(ordered.reduce((s, o) => s + o.order.units, 0))}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{ordered.reduce((s, o) => s + o.order.lines, 0)} item lines</p>
                </div>
            </section>

            {/* phones: one list at a time */}
            <div role="tablist" className="mb-3 grid grid-cols-2 gap-1 rounded-md border border-border bg-[#f2f4f8] p-0.5 xl:hidden">
                {(
                    [
                        ['waiting', `Not ordered (${summary.waiting})`],
                        ['ordered', `Ordered (${summary.ordered})`],
                    ] as const
                ).map(([k, label]) => (
                    <button
                        key={k}
                        type="button"
                        role="tab"
                        aria-selected={tab === k}
                        onClick={() => setTab(k)}
                        className={cn('rounded py-2 text-[13px] font-semibold transition-colors', tab === k ? 'bg-secondary text-secondary-foreground shadow-sm' : 'text-muted-foreground')}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="grid items-start gap-4 xl:grid-cols-[1fr_380px]">
                {/* stores still to order */}
                <section className={cn(panel, 'min-w-0', tab !== 'waiting' && 'max-xl:hidden')}>
                    <div className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <Clock className="size-4 text-[#9a5b00]" /> Not ordered yet
                            <span className="rounded-full bg-[#fff1d6] px-2 py-0.5 text-xs font-semibold tabular-nums text-[#8a5200]">{summary.waiting}</span>
                        </h2>
                        <div className="relative ml-auto w-full sm:w-64">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Find a store" aria-label="Find a store" className={cn(field, 'px-8')} />
                            {query && (
                                <button type="button" onClick={() => setQuery('')} aria-label="Clear search" className="absolute right-1.5 top-1/2 grid size-6 -translate-y-1/2 place-items-center rounded text-muted-foreground hover:bg-muted">
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                    </div>

                    <div className={listHeight}>
                        {waiting.length === 0 ? (
                            <p className="px-4 py-14 text-center text-sm text-muted-foreground">Every active store has ordered. 🎉</p>
                        ) : groups.length === 0 ? (
                            <p className="px-4 py-14 text-center text-sm text-muted-foreground">No store matches “{query}”.</p>
                        ) : (
                            groups.map((g) => {
                                const t = leftText(g.at);
                                return (
                                    <div key={g.at}>
                                        <div className="sticky top-0 z-10 flex items-center gap-2 border-b border-border bg-[#f2f4f8] px-4 py-1.5 text-xs">
                                            <span className="font-semibold tabular-nums">Cutoff {formatTime(g.time)}</span>
                                            <span className={cn('tabular-nums', t.tone)}>· {t.text}</span>
                                            <span className="ml-auto text-muted-foreground">
                                                {g.stores.length} {g.stores.length === 1 ? 'store' : 'stores'}
                                            </span>
                                        </div>
                                        <ul className="grid grid-cols-2 gap-1.5 p-3 lg:grid-cols-3 2xl:grid-cols-4">
                                            {g.stores.map((s) => (
                                                <li key={s.id}>
                                                    <Link
                                                        href={`/admin/stores/${s.id}`}
                                                        className={cn(
                                                            'flex h-full items-center gap-2 rounded-md border px-2.5 py-1.5 text-sm transition-colors hover:border-secondary hover:bg-accent/60',
                                                            !s.has_login ? 'border-[#f0b8a6] bg-[#fff6f3]' : s.in_progress ? 'border-[#c9d4fa] bg-[#f4f6ff]' : 'border-border bg-white',
                                                        )}
                                                    >
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block truncate font-medium leading-tight">{s.name.replace(/^BW\s+/, '')}</span>
                                                            <span className="block text-[11px] leading-tight text-muted-foreground">{s.ecpos_store_id ?? s.code}</span>
                                                        </span>
                                                        {!s.has_login && (
                                                            <span title="This store has no login yet" className="inline-flex items-center gap-1 rounded bg-[#ffe9e2] px-1.5 py-0.5 text-[10px] font-semibold text-[#9c2a00]">
                                                                <AlertTriangle className="size-3" /> no login
                                                            </span>
                                                        )}
                                                        {s.has_login && s.in_progress && <span className="rounded bg-[#e8ecfb] px-1.5 py-0.5 text-[10px] font-semibold text-[#1f3aa8]">started</span>}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </section>

                {/* stores that have ordered */}
                <section className={cn(panel, 'min-w-0', tab !== 'ordered' && 'max-xl:hidden')}>
                    <h2 className="flex items-center gap-2 border-b border-border px-4 py-3 text-sm font-semibold">
                        <CheckCircle2 className="size-4 text-[#16a34a]" /> Ordered
                        <span className="rounded-full bg-[#e3f5ea] px-2 py-0.5 text-xs font-semibold tabular-nums text-[#16683a]">{summary.ordered}</span>
                    </h2>
                    <div className={listHeight}>
                        {ordered.length === 0 ? (
                            <p className="px-4 py-14 text-center text-sm text-muted-foreground">No store has ordered yet.</p>
                        ) : shownOrdered.length === 0 ? (
                            <p className="px-4 py-14 text-center text-sm text-muted-foreground">No store matches “{query}”.</p>
                        ) : (
                            <ul className="divide-y divide-border/70">
                                {shownOrdered.map((s) => (
                                    <li key={s.id} className="flex items-center gap-3 px-4 py-2">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium leading-tight">{s.name}</p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                <Link href={`/orders/${s.order.id}`} className="font-mono text-secondary hover:underline">
                                                    {s.order.number}
                                                </Link>{' '}
                                                · {nf.format(s.order.units)} units · {s.order.lines} {s.order.lines === 1 ? 'item' : 'items'}
                                            </p>
                                        </div>
                                        <StatusBadge status={s.order.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
