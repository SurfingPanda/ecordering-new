import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';

import AnimatedNumber from '@/components/charts/animated-number';
import BarList from '@/components/charts/bar-list';
import DailyChart, { type DailyPoint } from '@/components/charts/daily-chart';
import ShareBar from '@/components/charts/share-bar';
import CatalogUnits from '@/components/catalog-units';
import OrderCards from '@/components/order-cards';
import { StatusBadge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogUnitsKey, catalogs, nf, shortDate } from '@/lib/charts';
import { formatDay } from '@/lib/format';
import { panel, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource, OrderSummary } from '@/types';

interface Kpi {
    value: number;
    previous: number;
    change: number | null;
}

interface TopItem {
    product_code: string;
    name: string;
    category: string | null;
    units: number;
    orders: number;
}

interface Props {
    scope: 'all' | 'store';
    range: number;
    period: { from: string; to: string };
    kpis: { orders: Kpi; units: Kpi; pending: number; items_ordered: number };
    daily: DailyPoint[];
    split: Record<ItemSource, { units: number; items: number }>;
    top_items: Record<ItemSource, TopItem[]>;
    top_categories: { category: string; source: ItemSource; units: number }[];
    not_ordered: { product_code: string; description: string; category: string | null; source: ItemSource }[];
    recent: OrderSummary[];
}

const ranges = [7, 30, 90];

export default function Dashboard({ scope, range, period, kpis, daily, split, top_items, top_categories, not_ordered, recent }: Props) {
    const [loading, setLoading] = useState(false);

    const setRange = (r: number) =>
        router.get(
            '/dashboard',
            { range: r },
            { preserveScroll: true, preserveState: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) },
        );

    return (
        <AppLayout
            title="Dashboard"
            actions={
                <div role="group" aria-label="Report period" className="flex rounded-md border border-border bg-white p-0.5">
                        {ranges.map((r) => (
                            <button
                                key={r}
                                type="button"
                                onClick={() => setRange(r)}
                                aria-pressed={range === r}
                                className={cn(
                                    'rounded px-2.5 py-1 text-xs font-semibold',
                                    range === r ? 'bg-secondary text-secondary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {r} days
                            </button>
                        ))}
                </div>
            }
        >
            <div className={cn('transition-opacity duration-200', loading && 'opacity-60')} aria-busy={loading}>
            <p className="mb-4 text-[13px] text-muted-foreground">
                Showing {shortDate(period.from)} – {shortDate(period.to)}.
            </p>

            {/* headline numbers */}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Tile label="Orders placed" kpi={kpis.orders} range={range} tone="blue" />
                <Tile label="Units ordered" kpi={kpis.units} range={range} tone="orange" />
                <Link href="/orders?status=pending" className={cn(tile, tones.blue, 'transition-[filter] hover:brightness-110')}>
                    <p className="text-[13px] text-foreground/80">Pending orders</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums"><AnimatedNumber value={kpis.pending} /></p>
                    <p className="mt-1 text-xs text-foreground/80">Waiting to be posted</p>
                </Link>
                <div className={cn(tile, tones.orange)}>
                    <p className="text-[13px] text-foreground/80">Different items ordered</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums"><AnimatedNumber value={kpis.items_ordered} /></p>
                    <p className="mt-1 text-xs text-foreground/80">Across all catalogs</p>
                </div>
            </div>

            {/* trend + split */}
            <div className="mt-5 grid gap-5 xl:grid-cols-[1fr_340px]">
                <Card title="Units ordered per day" subtitle="Stacked by catalog">
                    <DailyChart data={daily} animKey={range} />
                </Card>
                <Card title="Share by catalog" subtitle="Share of units ordered">
                    <ShareBar split={split} animKey={range} />
                </Card>
            </div>

            {/* most ordered */}
            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                {catalogs.map((src) => (
                    <Card key={src} title={`Most ordered ${catalogLabel[src]}`} subtitle="Units ordered, top 8">
                        <BarList
                            animKey={range}
                            rows={(top_items[src] ?? []).map((i) => ({
                                key: i.product_code,
                                label: i.name,
                                sublabel: `${i.product_code}${i.category ? ` · ${i.category}` : ''} · in ${i.orders} ${i.orders === 1 ? 'order' : 'orders'}`,
                                value: i.units,
                                color: catalogColor[src],
                            }))}
                        />
                    </Card>
                ))}
            </div>

            {/* categories + not ordered */}
            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <Card title="Top categories" subtitle="Units ordered, all catalogs">
                    <BarList
                        animKey={range}
                        rows={top_categories.map((c) => ({
                            key: `${c.source}-${c.category}`,
                            label: c.category,
                            sublabel: catalogLabel[c.source],
                            value: c.units,
                            color: catalogColor[c.source],
                        }))}
                    />
                </Card>

                <Card title="Not ordered in this period" subtitle="Items in the catalog nobody ordered">
                    {not_ordered.length === 0 ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">Every item was ordered at least once.</p>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {not_ordered.map((i) => (
                                <li key={i.product_code} className="flex items-center gap-3 py-2">
                                    <span className="size-2.5 shrink-0 rounded-[3px]" style={{ background: catalogColor[i.source] }} title={catalogLabel[i.source]} />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13px] font-medium">{i.description}</p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {i.product_code}
                                            {i.category ? ` · ${i.category}` : ''}
                                        </p>
                                    </div>
                                    <span className="text-xs text-muted-foreground">{catalogLabel[i.source]}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {/* recent orders */}
            <section className={cn(panel, 'mt-5')}>
                <div className="flex items-center justify-between border-b border-border px-4 py-3">
                    <h2 className="text-sm font-semibold">Recent orders</h2>
                    <Link href="/orders" className="text-[13px] font-medium text-secondary hover:underline">
                        View all
                    </Link>
                </div>
                <OrderCards orders={recent} showStore={scope === 'all'} empty="No orders yet." />
                <div className="overflow-x-auto max-md:hidden">
                    <table className="w-full min-w-[560px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>TR</th>
                                {scope === 'all' && <th className={th}>Store</th>}
                                <th className={th}>Ordered by</th>
                                <th className={th}>Order date</th>
                                {catalogs.map((s) => (
                                    <th key={s} className={cn(th, 'text-right')}>
                                        {catalogLabel[s]}
                                    </th>
                                ))}
                                <th className={th}>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recent.length === 0 && (
                                <tr>
                                    <td colSpan={scope === 'all' ? 9 : 8} className="px-4 py-10 text-center text-sm text-muted-foreground">
                                        No orders yet.
                                    </td>
                                </tr>
                            )}
                            {recent.map((o) => (
                                <tr key={o.id} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc] hover:bg-accent/60">
                                    <td className={cn(td, 'font-medium')}>
                                        <Link href={`/orders/${o.id}`} className="font-mono text-secondary hover:underline">
                                            {o.number}
                                        </Link>
                                    </td>
                                    {scope === 'all' && <td className={cn(td, 'whitespace-nowrap')}>{o.store ? `${o.store.code} — ${o.store.name}` : '—'}</td>}
                                    <td className={td}>{o.ordered_by}</td>
                                    <td className={cn(td, 'text-muted-foreground')}>{formatDay(o.order_date)}</td>
                                    {catalogs.map((s) => (
                                        <td key={s} className={cn(td, 'text-right')}>
                                            <CatalogUnits value={o[catalogUnitsKey[s]]} />
                                        </td>
                                    ))}
                                    <td className={td}>
                                        <StatusBadge status={o.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
            </div>
        </AppLayout>
    );
}

function Card({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
    return (
        <section className={cn(panel, 'min-w-0')}>
            <div className="border-b border-border px-4 py-3">
                <h2 className="text-sm font-semibold">{title}</h2>
                {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

// Headline cards: soft pastel gradients from the logo colors, with dark text for contrast.
const tile = 'block rounded-md border px-4 py-3.5 text-foreground shadow-sm';
const tones = {
    blue: 'border-[#a9bbf8] bg-gradient-to-br from-[#eef2ff] via-[#bccaf9] to-[#7390f2]',
    orange: 'border-[#ffb996] bg-gradient-to-br from-[#fff3ea] via-[#ffcaa9] to-[#ff8a50]',
};

function Tile({ label, kpi, range, tone }: { label: string; kpi: Kpi; range: number; tone: keyof typeof tones }) {
    const { change } = kpi;
    const Icon = change === null || change === 0 ? Minus : change > 0 ? ArrowUpRight : ArrowDownRight;

    return (
        <div className={cn(tile, tones[tone])}>
            <p className="text-[13px] text-foreground/80">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums"><AnimatedNumber value={kpi.value} /></p>
            <p className="mt-1 flex items-center gap-1 text-xs text-foreground/80">
                <Icon className="size-3.5" />
                {change === null ? `No data for the previous ${range} days` : `${change > 0 ? '+' : ''}${change}% vs previous ${range} days`}
            </p>
        </div>
    );
}
