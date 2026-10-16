import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarClock, CalendarSearch, Download, Loader2, PackageCheck, Search, Sparkles, Layers } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { catalogLabel, catalogs, nf } from '@/lib/charts';
import { formatDay } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

interface Row {
    code: string;
    source: ItemSource;
    name: string;
    category: string | null;
    moq: number | null;
    history_units: number;
    avg_per_day: number;
    forecast: number;
}

interface Props {
    stores: { id: number; code: string; name: string }[];
    store_id: number | null;
    for_store: boolean;
    all_stores: boolean;
    store_count: number | null;
    from: string;
    to: string;
    target: string;
    target_day: string;
    source: ItemSource | null;
    days: number;
    same_weekday_days: number;
    items: Row[];
}

/** The loader stays up at least this long, so a fast answer doesn't just flash the table in. */
const MIN_LOADING_MS = 900;

/** Forecast demand plus the safety buffer, less what is on hand; never below the item's MOQ when something is needed. */
function suggest(r: Row, buffer: number, onHand: number): number {
    const need = Math.ceil(r.forecast * (1 + buffer / 100) - 1e-9) - onHand;
    if (need <= 0) return 0;
    return Math.max(need, r.moq ?? 0);
}

const key = (r: Row) => `${r.source}|${r.code}`;
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const addDays = (n: number, base = new Date()) => {
    const d = new Date(base);
    d.setDate(d.getDate() + n);
    return d;
};
const dec = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 });

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors',
                active ? 'border-secondary bg-secondary text-secondary-foreground' : 'border-border bg-white text-muted-foreground hover:border-secondary/50 hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

function Stat({ icon: Icon, label, value, hint, tone }: { icon: typeof Layers; label: string; value: string; hint: string; tone: 'blue' | 'orange' }) {
    return (
        <div className={cn('flex items-center gap-3 rounded-md border p-4 shadow-sm', tone === 'blue' ? 'border-[#cdd7fb] bg-gradient-to-br from-[#eef2ff] to-[#dfe6ff]' : 'border-[#fbd9c3] bg-gradient-to-br from-[#fff3ea] to-[#ffe3d1]')}>
            <span className={cn('grid size-10 shrink-0 place-items-center rounded-lg', tone === 'blue' ? 'bg-[#3b63f0] text-white' : 'bg-[#ff5000] text-white')}>
                <Icon className="size-5" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-semibold text-muted-foreground">{label}</p>
                <p className="truncate text-xl font-bold tabular-nums leading-tight">{value}</p>
                <p className="truncate text-xs text-muted-foreground">{hint}</p>
            </div>
        </div>
    );
}

/** Grey placeholder rows shown while the forecast is being worked out. */
function TableSkeleton() {
    return (
        <div role="status" aria-live="polite" aria-label="Working out the forecast" className="divide-y divide-border">
            <div className="flex items-center gap-2 bg-muted/40 px-4 py-3 text-sm font-medium text-muted-foreground">
                <Loader2 className="size-4 animate-spin text-secondary" /> Working out the forecast…
            </div>
            {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="flex animate-pulse items-center gap-4 px-4 py-3.5" style={{ animationDelay: `${i * 80}ms` }}>
                    <div className="min-w-0 flex-1 space-y-2">
                        <div className="h-3.5 rounded bg-muted" style={{ width: `${45 + ((i * 17) % 35)}%` }} />
                        <div className="h-2.5 w-1/4 rounded bg-muted/70" />
                    </div>
                    <div className="hidden h-3.5 w-12 rounded bg-muted sm:block" />
                    <div className="hidden h-3.5 w-12 rounded bg-muted md:block" />
                    <div className="h-3.5 w-12 rounded bg-muted" />
                    <div className="h-9 w-20 rounded bg-muted" />
                    <div className="h-5 w-10 rounded bg-muted" />
                </div>
            ))}
        </div>
    );
}

/** "Forecast": what the store will probably need on a chosen day, learned from its orders in a past date range. */
export default function Forecast({ stores, store_id, for_store, all_stores, store_count, from, to, target, target_day, source, days, same_weekday_days, items }: Props) {
    const [f, setF] = useState({ from, to, target, source: source ?? '', store: store_id ? String(store_id) : '' });
    const [buffer, setBuffer] = useState(10);
    const [onHand, setOnHand] = useState<Record<string, number>>({});
    const [query, setQuery] = useState('');
    const [onlyNeeded, setOnlyNeeded] = useState(true);
    const [loading, setLoading] = useState(false);
    const [shown, setShown] = useState(true); // false for one frame after loading, so the rows can fade in
    const startedAt = useRef(0);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const valid = Boolean(f.from && f.to && f.target && f.from <= f.to);
    const apply = () => {
        if (!valid || loading) return;
        router.get(
            '/forecast',
            { from: f.from, to: f.to, target: f.target, source: f.source || undefined, store: f.store || undefined },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onStart: () => {
                    startedAt.current = Date.now();
                    setShown(false);
                    setLoading(true);
                },
                onFinish: () => {
                    const wait = Math.max(0, MIN_LOADING_MS - (Date.now() - startedAt.current));
                    timer.current = window.setTimeout(() => {
                        setLoading(false);
                        requestAnimationFrame(() => setShown(true));
                    }, wait);
                },
            },
        );
    };

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        return items
            .map((r) => ({ r, order: suggest(r, buffer, onHand[key(r)] ?? 0) }))
            .filter(({ r, order }) => (!onlyNeeded || order > 0) && (!q || r.name.toLowerCase().includes(q) || r.code.toLowerCase().includes(q)));
    }, [items, buffer, onHand, query, onlyNeeded]);

    const exportCsv = () => {
        const esc = (v: string | number) => `"${String(v).replace(/"/g, '""')}"`;
        const lines = [['Code', 'Item', 'Catalog', 'Forecast', 'On hand', 'Order', 'MOQ'].map(esc).join(',')].concat(
            rows.map(({ r, order }) => [r.code, r.name, catalogLabel[r.source], r.forecast, onHand[key(r)] ?? 0, order, r.moq ?? ''].map(esc).join(',')),
        );
        const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: `forecast-${target}.csv` });
        a.click();
        URL.revokeObjectURL(url);
    };

    const toOrder = useMemo(() => items.filter((r) => suggest(r, buffer, onHand[key(r)] ?? 0) > 0).length, [items, buffer, onHand]);
    const totalUnits = useMemo(() => items.reduce((n, r) => n + suggest(r, buffer, onHand[key(r)] ?? 0), 0), [items, buffer, onHand]);
    const method = same_weekday_days >= 2 ? `average of ${same_weekday_days} ${target_day}s` : `daily average (under 2 ${target_day}s in range)`;

    const today = new Date();
    const historyPresets = [7, 14, 28, 90].map((n) => ({ n, from: ymd(addDays(-(n - 1))), to: ymd(today) }));
    const targetPresets = [
        { label: 'Tomorrow', v: ymd(addDays(1)) },
        { label: 'In 3 days', v: ymd(addDays(3)) },
        { label: 'Next week', v: ymd(addDays(7)) },
    ];

    return (
        <AppLayout title="Forecast">
            <p className="mb-4 text-[13px] text-muted-foreground">
                {all_stores
                    ? 'The combined forecast of every store: choose the past period to learn from and the day to plan for, and see how much of each item the stores will order in total. Pick a single store to narrow it down.'
                    : 'Choose the past period to learn from and the day you want to plan for. We’ll suggest what to stock and how much to order.'}
            </p>

            {/* ---- 1. what to forecast */}
            <section className={cn(panel, 'mb-4 p-4 sm:p-5')}>
                <div className="grid gap-4 lg:grid-cols-[1fr_1fr_auto]">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm font-semibold">
                            <span className="grid size-6 place-items-center rounded-full bg-secondary text-xs text-secondary-foreground">1</span> Past orders to learn from
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <label className="text-xs font-medium text-muted-foreground">
                                From
                                <Input type="date" className={cn(field, 'mt-1 text-foreground')} value={f.from} max={f.to || undefined} onChange={(e) => setF({ ...f, from: e.target.value })} />
                            </label>
                            <label className="text-xs font-medium text-muted-foreground">
                                To
                                <Input type="date" className={cn(field, 'mt-1 text-foreground')} value={f.to} min={f.from || undefined} onChange={(e) => setF({ ...f, to: e.target.value })} />
                            </label>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {historyPresets.map((p) => (
                                <Chip key={p.n} active={f.from === p.from && f.to === p.to} onClick={() => setF({ ...f, from: p.from, to: p.to })}>
                                    Last {p.n} days
                                </Chip>
                            ))}
                        </div>
                    </div>

                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm font-semibold">
                            <span className="grid size-6 place-items-center rounded-full bg-secondary text-xs text-secondary-foreground">2</span> Day to plan for
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <label className="text-xs font-medium text-muted-foreground">
                                Forecast date
                                <Input type="date" className={cn(field, 'mt-1 text-foreground')} value={f.target} onChange={(e) => setF({ ...f, target: e.target.value })} />
                            </label>
                            <label className="text-xs font-medium text-muted-foreground">
                                Catalog
                                <select className={cn(selectCls, 'mt-1 w-full text-foreground')} value={f.source} onChange={(e) => setF({ ...f, source: e.target.value as ItemSource | '' })}>
                                    <option value="">All catalogs</option>
                                    {catalogs.map((c) => (
                                        <option key={c} value={c}>
                                            {catalogLabel[c]}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {targetPresets.map((p) => (
                                <Chip key={p.label} active={f.target === p.v} onClick={() => setF({ ...f, target: p.v })}>
                                    {p.label}
                                </Chip>
                            ))}
                        </div>
                    </div>

                    <div className="flex flex-col justify-end gap-2 lg:w-44">
                        {for_store && (
                            <label className="text-xs font-medium text-muted-foreground">
                                Store
                                <select className={cn(selectCls, 'mt-1 w-full text-foreground')} value={f.store} onChange={(e) => setF({ ...f, store: e.target.value })}>
                                    {all_stores && <option value="">All stores ({store_count})</option>}
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <Button type="button" size="lg" className="w-full gap-2 bg-[#ff5000] font-bold text-white hover:bg-[#e54800]" disabled={!valid || loading} aria-busy={loading} onClick={apply}>
                            {loading ? <Loader2 className="animate-spin" /> : <Sparkles />}
                            {loading ? 'Forecasting…' : 'Forecast'}
                        </Button>
                        {!valid && <p className="text-xs text-[#b33b00]">“From” must be on or before “To”.</p>}
                    </div>
                </div>
            </section>

            {/* ---- 2. the answer */}
            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                {loading ? (
                    Array.from({ length: 3 }).map((_, i) => <div key={i} className="h-[92px] animate-pulse rounded-md border border-border bg-muted/60" />)
                ) : (
                    <>
                        <Stat icon={CalendarClock} tone="blue" label="Forecast for" value={`${target_day}, ${formatDay(target)}`} hint={`Using the ${method}`} />
                        <Stat icon={PackageCheck} tone="orange" label="Items to order" value={nf.format(toOrder)} hint={`of ${nf.format(items.length)} items ${all_stores && !store_id ? 'the stores' : 'you'} ordered before`} />
                        <Stat icon={Layers} tone="blue" label="Total units to order" value={nf.format(totalUnits)} hint={`includes a ${buffer}% safety buffer`} />
                    </>
                )}
            </div>

            <section className={panel}>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-border p-4">
                    <div className="mr-auto min-w-[14rem]">
                        <h2 className="text-sm font-semibold">What to order</h2>
                        <p className="text-xs text-muted-foreground">
                            Based on {formatDay(from)} – {formatDay(to)} ({days} days)
                        </p>
                    </div>
                    <label className="flex items-center gap-2 text-xs font-semibold" title="Extra stock on top of the forecast, in case the day sells more than average">
                        Safety buffer
                        <span className="relative">
                            <Input type="number" min={0} max={200} className={cn(field, 'w-20 pr-6')} value={buffer} onChange={(e) => setBuffer(Math.max(0, Number(e.target.value) || 0))} />
                            <span className="pointer-events-none absolute right-2.5 top-2 text-sm text-muted-foreground">%</span>
                        </span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-2 text-xs font-semibold">
                        <input type="checkbox" className="size-4 accent-[#3b63f0]" checked={onlyNeeded} onChange={(e) => setOnlyNeeded(e.target.checked)} />
                        Only items to order
                    </label>
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Find an item" aria-label="Find an item" className={cn(field, 'w-48 pl-8')} />
                    </div>
                    <Button type="button" variant="secondary" size="sm" className="gap-1.5" onClick={exportCsv} disabled={loading || rows.length === 0}>
                        <Download /> Export
                    </Button>
                </div>

                {loading ? (
                    <TableSkeleton />
                ) : items.length === 0 ? (
                    <div className="grid place-items-center gap-2 px-6 py-14 text-center">
                        <span className="grid size-12 place-items-center rounded-full bg-muted text-muted-foreground">
                            <CalendarSearch className="size-6" />
                        </span>
                        <p className="font-semibold">No orders in that period</p>
                        <p className="max-w-sm text-sm text-muted-foreground">There’s nothing to learn from yet. Try a longer range, like the last 28 or 90 days.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem]">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className={th}>Item</th>
                                    <th className={cn(th, 'text-right')}>Ordered</th>
                                    <th className={cn(th, 'text-right')}>Avg / day</th>
                                    <th className={cn(th, 'text-right')}>Forecast</th>
                                    <th className={cn(th, 'text-right')}>On hand</th>
                                    <th className={cn(th, 'text-right')}>Order</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {rows.map(({ r, order }, i) => (
                                    <tr
                                        key={key(r)}
                                        className={cn('transition-all duration-300 ease-out hover:bg-muted/30', shown ? 'translate-y-0 opacity-100' : 'translate-y-1 opacity-0')}
                                        style={{ transitionDelay: shown ? `${Math.min(i, 12) * 30}ms` : '0ms' }}
                                    >
                                        <td className={td}>
                                            <p className="font-medium">{r.name}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {r.code} · {catalogLabel[r.source]}
                                                {r.moq ? ` · MOQ ${r.moq}` : ''}
                                            </p>
                                        </td>
                                        <td className={cn(td, 'text-right tabular-nums text-muted-foreground')}>{nf.format(r.history_units)}</td>
                                        <td className={cn(td, 'text-right tabular-nums text-muted-foreground')}>{dec.format(r.avg_per_day)}</td>
                                        <td className={cn(td, 'text-right font-medium tabular-nums')}>{dec.format(r.forecast)}</td>
                                        <td className={cn(td, 'text-right')}>
                                            <Input
                                                type="number"
                                                inputMode="numeric"
                                                min={0}
                                                aria-label={`On hand: ${r.name}`}
                                                className={cn(field, 'ml-auto w-20 text-right')}
                                                value={onHand[key(r)] ?? ''}
                                                placeholder="0"
                                                onChange={(e) => setOnHand({ ...onHand, [key(r)]: Math.max(0, Math.floor(Number(e.target.value) || 0)) })}
                                            />
                                        </td>
                                        <td className={cn(td, 'text-right')}>
                                            <span className={cn('inline-block min-w-12 rounded-md px-2.5 py-1 text-center text-base font-bold tabular-nums', order > 0 ? 'bg-[#e7ecff] text-[#1f3aa8]' : 'text-muted-foreground')}>{nf.format(order)}</span>
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="p-10 text-center text-sm text-muted-foreground">
                                            {query ? 'No items match that search.' : 'Nothing to order. Turn off “Only items to order” to see every item.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}

                {!loading && rows.length > 0 && (
                    <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                        Showing {nf.format(rows.length)} items. <span className="font-semibold text-foreground">Order</span> = forecast + {buffer}% buffer − on hand, raised to the item’s MOQ. Type what you have in <span className="font-semibold text-foreground">On hand</span> to adjust.
                    </p>
                )}
            </section>
        </AppLayout>
    );
}
