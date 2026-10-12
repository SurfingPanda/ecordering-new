import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogs, niceMax, nf } from '@/lib/charts';
import { panel, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

interface Compare {
    latest: number;
    previous: number;
    change: number | null;
    sum: number;
}

interface Props {
    by: 'week' | 'month';
    source: ItemSource | null;
    periods: { label: string; long: string; partial: boolean }[];
    overall: Compare & { units: number[]; orders: number[] };
    stores: (Compare & { id: number; code: string; name: string; active: boolean; series: number[] })[];
    items: (Compare & { code: string; name: string; source: ItemSource; category: string | null; series: number[] })[];
    item_count: number;
    quiet_stores: string[];
}

const BLUE = '#3b63f0';

/** Up is blue (more ordered), down is orange; neither is "good" or "bad" by itself, so no red/green. */
function Change({ value, latest, previous }: { value: number | null; latest: number; previous: number }) {
    if (value === null) return <span className="text-xs text-muted-foreground">{latest > 0 ? 'new' : '–'}</span>;
    const Icon = value > 0 ? ArrowUp : value < 0 ? ArrowDown : Minus;

    return (
        <span
            title={`${nf.format(previous)} → ${nf.format(latest)}`}
            className={cn('inline-flex items-center gap-0.5 text-xs font-semibold tabular-nums', value > 0 ? 'text-[#1f3aa8]' : value < 0 ? 'text-[#b33b00]' : 'text-muted-foreground')}
        >
            <Icon className="size-3" /> {Math.abs(value)}%
        </span>
    );
}

/** One small bar per period; the running period is drawn lighter because it isn't finished. */
function Spark({ series, periods, color = BLUE }: { series: number[]; periods: Props['periods']; color?: string }) {
    const max = Math.max(1, ...series);

    return (
        <div className="flex h-8 items-end gap-[2px] sm:gap-[3px]" role="img" aria-label={`Units per period, latest ${series[series.length - 1]}`}>
            {series.map((v, i) => (
                <div
                    key={i}
                    title={`${periods[i].long}${periods[i].partial ? ' (so far)' : ''}: ${nf.format(v)} units`}
                    className="w-[5px] rounded-t-[2px] sm:w-[7px]"
                    style={{ height: `${v === 0 ? 2 : Math.max(8, (v / max) * 100)}%`, background: color, opacity: periods[i].partial ? 0.4 : v === 0 ? 0.25 : 1 }}
                />
            ))}
        </div>
    );
}

function Segmented<T extends string>({ value, options, onChange, label }: { value: T; options: { key: T; label: string }[]; onChange: (v: T) => void; label: string }) {
    return (
        <div role="group" aria-label={label} className="flex rounded-md border border-border bg-white p-0.5">
            {options.map((o) => (
                <button
                    key={o.key}
                    type="button"
                    aria-pressed={value === o.key}
                    onClick={() => onChange(o.key)}
                    className={cn('rounded px-2.5 py-1 text-xs font-semibold', value === o.key ? 'bg-secondary text-secondary-foreground' : 'text-muted-foreground hover:text-foreground')}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

/** "Ordering Trends": units per week or month, for the whole business, each store and each item. */
export default function Trends({ by, source, periods, overall, stores, items, item_count, quiet_stores }: Props) {
    const [tab, setTab] = useState<'stores' | 'items'>('stores');
    const go = (next: { by?: string; source?: string | null }) =>
        router.get('/admin/trends', { by: next.by ?? by, source: (next.source === undefined ? source : next.source) || undefined }, { preserveScroll: true, preserveState: true, replace: true });

    const unit = by === 'month' ? 'month' : 'week';
    const max = niceMax(Math.max(...overall.units));
    const lastIdx = periods.length - 2; // last complete period

    return (
        <AppLayout
            title="Ordering Trends"
            actions={<Segmented label="Group by" value={by} options={[{ key: 'week', label: 'Weekly' }, { key: 'month', label: 'Monthly' }]} onChange={(v) => go({ by: v })} />}
        >
            <p className="mb-4 text-[13px] text-muted-foreground">
                The last {periods.length} {unit}s of placed orders. The latest {unit} is compared with the one before it; the current {unit} is still running, so it is shown lighter and not compared.
            </p>

            {quiet_stores.length > 0 && (
                <div role="status" className="mb-4 rounded-md border border-[#f3d9a8] bg-[#fff6e5] px-4 py-3 text-sm text-[#7a4a00]">
                    <p className="font-semibold">
                        {quiet_stores.length} {quiet_stores.length === 1 ? 'store has' : 'stores have'} stopped ordering
                    </p>
                    <p className="mt-0.5 text-xs">
                        No orders last {unit} (but some the {unit} before): {quiet_stores.slice(0, 8).join(', ')}
                        {quiet_stores.length > 8 ? ` and ${quiet_stores.length - 8} more` : ''}.
                    </p>
                </div>
            )}

            {/* all stores together */}
            <section className={cn(panel, 'p-4')}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-semibold">Units ordered per {unit}</h2>
                        <p className="text-xs text-muted-foreground">All stores together</p>
                    </div>
                    <p className="text-sm">
                        <span className="font-semibold tabular-nums">{nf.format(overall.latest)}</span> units last {unit} <Change value={overall.change} latest={overall.latest} previous={overall.previous} />
                    </p>
                </div>

                <div className="mt-4 flex gap-2">
                    <div className="flex h-44 flex-col justify-between text-right text-[11px] tabular-nums text-muted-foreground">
                        <span>{nf.format(max)}</span>
                        <span>{nf.format(max / 2)}</span>
                        <span>0</span>
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex h-44 items-end gap-1 border-b border-l border-border/80 px-1 sm:gap-2">
                            {overall.units.map((v, i) => (
                                <div key={i} className="flex h-full flex-1 items-end" title={`${periods[i].long}${periods[i].partial ? ' (so far)' : ''}: ${nf.format(v)} units in ${overall.orders[i]} ${overall.orders[i] === 1 ? 'order' : 'orders'}`}>
                                    <div className="anim-grow-y w-full rounded-t-[4px]" style={{ height: `${Math.max(v === 0 ? 0 : 2, (v / max) * 100)}%`, background: BLUE, opacity: periods[i].partial ? 0.4 : 1 }} />
                                </div>
                            ))}
                        </div>
                        <div className="mt-1.5 flex gap-1 px-1 sm:gap-2 [&>span]:min-w-0 [&>span]:overflow-visible">
                            {periods.map((p, i) => (
                                <span key={i} className={cn('flex-1 whitespace-nowrap text-center text-[10px] text-muted-foreground sm:text-[11px]', i === lastIdx && 'font-semibold text-foreground', i % 2 !== lastIdx % 2 && 'max-sm:invisible')}>
                                    {p.label}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            {/* per store / per item */}
            <section className={cn(panel, 'mt-5 overflow-hidden')}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <Segmented
                        label="Break down by"
                        value={tab}
                        options={[{ key: 'stores', label: 'By store' }, { key: 'items', label: 'By item' }]}
                        onChange={setTab}
                    />
                    {tab === 'items' && (
                        <select
                            aria-label="Catalog"
                            value={source ?? ''}
                            onChange={(e) => go({ source: e.target.value || null })}
                            className="h-9 rounded-md border border-input bg-card px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                        >
                            <option value="">All catalogs</option>
                            {catalogs.map((s) => (
                                <option key={s} value={s}>
                                    {catalogLabel[s]}
                                </option>
                            ))}
                        </select>
                    )}
                </div>

                <div className="no-scrollbar max-h-[calc(100svh-26rem)] min-h-[300px] overflow-auto">
                    <table className="w-full border-collapse sm:min-w-[620px]">
                        <thead className="sticky top-0 z-10 bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>{tab === 'stores' ? 'Store' : 'Item'}</th>
                                <th className={cn(th, 'text-center')}><span className="max-sm:hidden">Last {periods.length} {unit}s</span><span className="sm:hidden">Trend</span></th>
                                <th className={cn(th, 'text-right max-sm:hidden')}>Previous</th>
                                <th className={cn(th, 'text-right')}>Latest</th>
                                <th className={cn(th, 'text-right')}>Change</th>
                                <th className={cn(th, 'text-right max-sm:hidden')}>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tab === 'stores' &&
                                stores.map((s) => (
                                    <tr key={s.id} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc]">
                                        <td className={td}>
                                            <span className="font-medium">{s.name}</span> <span className="font-mono text-xs text-muted-foreground">{s.code}</span>
                                            {!s.active && <span className="ml-1.5 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">inactive</span>}
                                        </td>
                                        <td className={td}><div className="flex justify-center"><Spark series={s.series} periods={periods} /></div></td>
                                        <td className={cn(td, 'text-right tabular-nums text-muted-foreground max-sm:hidden')}>{nf.format(s.previous)}</td>
                                        <td className={cn(td, 'text-right font-semibold tabular-nums')}>{nf.format(s.latest)}</td>
                                        <td className={cn(td, 'text-right')}><Change value={s.change} latest={s.latest} previous={s.previous} /></td>
                                        <td className={cn(td, 'text-right tabular-nums max-sm:hidden')}>{nf.format(s.sum)}</td>
                                    </tr>
                                ))}
                            {tab === 'items' &&
                                items.map((i) => (
                                    <tr key={i.code} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc]">
                                        <td className={td}>
                                            <span className="font-medium">{i.name}</span>
                                            <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                <span className="size-2 rounded-[2px]" style={{ background: catalogColor[i.source] }} />
                                                <span className="font-mono">{i.code}</span>
                                                {i.category && <span>· {i.category}</span>}
                                            </span>
                                        </td>
                                        <td className={td}><div className="flex justify-center"><Spark series={i.series} periods={periods} color={catalogColor[i.source]} /></div></td>
                                        <td className={cn(td, 'text-right tabular-nums text-muted-foreground max-sm:hidden')}>{nf.format(i.previous)}</td>
                                        <td className={cn(td, 'text-right font-semibold tabular-nums')}>{nf.format(i.latest)}</td>
                                        <td className={cn(td, 'text-right')}><Change value={i.change} latest={i.latest} previous={i.previous} /></td>
                                        <td className={cn(td, 'text-right tabular-nums max-sm:hidden')}>{nf.format(i.sum)}</td>
                                    </tr>
                                ))}
                            {((tab === 'stores' && stores.length === 0) || (tab === 'items' && items.length === 0)) && (
                                <tr>
                                    <td colSpan={6}  className="px-5 py-14 text-center text-sm text-muted-foreground">
                                        No orders in this period yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <p className="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                    {tab === 'items' ? `Showing the ${items.length} busiest of ${nf.format(item_count)} items, sorted by last ${unit}. ` : 'Sorted by last ' + unit + '. '}
                    Hover a bar for the exact figure.
                </p>
            </section>
        </AppLayout>
    );
}
