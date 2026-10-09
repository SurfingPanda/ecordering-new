import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Download, Search, SlidersHorizontal } from 'lucide-react';

import { StatusBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogSingular, catalogUnitsKey, catalogs, nf } from '@/lib/charts';
import { formatDate, formatDay } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource, OrderStatus, Paginated, StoreRef } from '@/types';

interface Filters {
    from: string;
    to: string;
    store: number | null;
    status: OrderStatus | null;
    type: ItemSource | null;
    ref: string;
    product: string;
    view: 'detail' | 'products';
    sort: string;
    dir: 'asc' | 'desc';
}

interface DetailRow {
    order_id: number;
    number: string;
    order_date: string;
    store_code: string | null;
    store_name: string | null;
    product_code: string;
    name: string;
    category: string | null;
    source: ItemSource;
    quantity: number;
    status: OrderStatus;
    submitted_at: string | null;
}

interface PivotRow {
    product_code: string;
    name: string;
    category: string | null;
    source: ItemSource;
    by_store: Record<number, number>;
    total: number;
}

interface Props {
    filters: Filters;
    summary: {
        stores: number;
        orders: number;
        products: number;
        units: number;
        bw_units: number;
        warehouse_units: number;
        merchandise_units: number;
        rejects_units: number;
        status: Record<OrderStatus, number>;
    };
    detail: Paginated<DetailRow> | null;
    pivot: {
        stores: StoreRef[];
        rows: PivotRow[];
        totals: { total: number; by_store: Record<number, number> };
        meta?: { from: number | null; to: number | null; total: number; links: Paginated<unknown>['links'] };
    } | null;
    stores: StoreRef[];
    today: string;
}

/** YYYY-MM-DD arithmetic that doesn't depend on the browser's timezone. */
function shift(ymd: string, days: number) {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));
    return date.toISOString().slice(0, 10);
}

function mondayOf(ymd: string) {
    const [y, m, d] = ymd.split('-').map(Number);
    const dow = new Date(Date.UTC(y, m - 1, d)).getUTCDay(); // 0 = Sunday
    return shift(ymd, -((dow + 6) % 7));
}

const columns: { key: string; label: string; right?: boolean }[] = [
    { key: 'order_date', label: 'Order date' },
    { key: 'number', label: 'TR' },
    { key: 'store_code', label: 'Store code' },
    { key: 'store_name', label: 'Store' },
    { key: 'product_code', label: 'Product code' },
    { key: 'name', label: 'Product' },
    { key: 'category', label: 'Category' },
    { key: 'type', label: 'Item type' },
    { key: 'quantity', label: 'Qty', right: true },
    { key: 'status', label: 'Status' },
    { key: 'submitted', label: 'Submitted' },
];

export default function Consolidated({ filters, summary, detail, pivot, stores, today }: Props) {
    // what's typed in the form; only becomes the active filter when "Apply filter" is pressed
    const [draft, setDraft] = useState({ from: filters.from, to: filters.to, store: filters.store ? String(filters.store) : '', status: filters.status ?? '', type: filters.type ?? '', ref: filters.ref, product: filters.product });
    const [loading, setLoading] = useState(false);

    const params = (f: Partial<Filters> & Record<string, unknown>) => {
        const all = { ...filters, ...f };
        return {
            from: all.from,
            to: all.to,
            store: all.store ?? undefined,
            status: all.status ?? undefined,
            type: all.type ?? undefined,
            ref: all.ref || undefined,
            product: all.product || undefined,
            view: all.view === 'products' ? 'products' : undefined,
            sort: all.view === 'detail' && (all.sort !== 'order_date' || all.dir !== 'desc') ? all.sort : undefined,
            dir: all.view === 'detail' && (all.sort !== 'order_date' || all.dir !== 'desc') ? all.dir : undefined,
        };
    };

    const go = (f: Partial<Filters>) =>
        router.get('/admin/consolidated', params(f), { preserveScroll: true, replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });

    const apply = (e?: React.FormEvent) => {
        e?.preventDefault();
        go({
            from: draft.from || today,
            to: draft.to || today,
            store: draft.store ? Number(draft.store) : null,
            status: (draft.status || null) as OrderStatus | null,
            type: (draft.type || null) as ItemSource | null,
            ref: draft.ref.trim(),
            product: draft.product.trim(),
        });
    };

    const quick = (from: string, to: string) => {
        setDraft((d) => ({ ...d, from, to }));
        go({ from, to });
    };

    const reset = () => {
        setDraft({ from: today, to: today, store: '', status: '', type: '', ref: '', product: '' });
        router.get('/admin/consolidated', filters.view === 'products' ? { view: 'products' } : {}, { replace: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
    };

    const sortBy = (key: string) => go({ sort: key, dir: filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc' });

    const exportUrl = '/admin/consolidated/export?' + new URLSearchParams(Object.fromEntries(Object.entries(params({})).filter(([, v]) => v !== undefined)) as Record<string, string>).toString();

    const quickRanges = [
        { label: 'Today', from: today, to: today },
        { label: 'Yesterday', from: shift(today, -1), to: shift(today, -1) },
        { label: 'This week', from: mondayOf(today), to: today },
        { label: 'Last 7 days', from: shift(today, -6), to: today },
    ];

    const sameDay = filters.from === filters.to;
    const longDay = (ymd: string) => new Date(ymd + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    const rangeLabel = sameDay ? longDay(filters.from) : `${formatDay(filters.from)} – ${formatDay(filters.to)}`;

    // the extra filters stay tucked away until they're needed (or already in use)
    const moreActive = [filters.status, filters.type, filters.ref].filter(Boolean).length;
    const anyFilter = moreActive + [filters.store, filters.product].filter(Boolean).length;
    const [showMore, setShowMore] = useState(moreActive > 0);

    const unitsTotal = catalogs.reduce((s, k) => s + summary[catalogUnitsKey[k]], 0);

    return (
        <AppLayout
            title="All Store Orders"
            actions={
                <Button asChild variant="ghost" size="sm" className="border border-border">
                    <a href={exportUrl}>
                        <Download /> Export CSV
                    </a>
                </Button>
            }
        >
            {/* 1 — the scope of the report: which dates, which orders */}
            <section className={cn(panel, 'mb-5')}>
                <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Showing orders dated</p>
                        <h2 className="mt-0.5 text-xl font-semibold leading-tight">{rangeLabel}</h2>
                    </div>
                    <div role="group" aria-label="Quick date ranges" className="inline-flex rounded-md border border-border bg-[#f2f4f8] p-0.5">
                        {quickRanges.map((q) => {
                            const active = filters.from === q.from && filters.to === q.to;
                            return (
                                <button
                                    key={q.label}
                                    type="button"
                                    onClick={() => quick(q.from, q.to)}
                                    aria-pressed={active}
                                    className={cn(
                                        'rounded px-3 py-1.5 text-[13px] font-semibold transition-colors',
                                        active ? 'bg-white text-secondary shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {q.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <form onSubmit={apply} className="p-5">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Labeled label="From">
                            <Input type="date" className={field} value={draft.from} onChange={(e) => setDraft({ ...draft, from: e.target.value })} />
                        </Labeled>
                        <Labeled label="To">
                            <Input type="date" className={field} value={draft.to} onChange={(e) => setDraft({ ...draft, to: e.target.value })} />
                        </Labeled>
                        <Labeled label="Store">
                            <select className={cn(selectCls, 'w-full')} value={draft.store} onChange={(e) => setDraft({ ...draft, store: e.target.value })}>
                                <option value="">All stores</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.code} — {s.name}
                                    </option>
                                ))}
                            </select>
                        </Labeled>
                        <Labeled label="Product name or SKU">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input className={cn(field, 'pl-8')} placeholder="Search products" value={draft.product} onChange={(e) => setDraft({ ...draft, product: e.target.value })} />
                            </div>
                        </Labeled>
                    </div>

                    {showMore && (
                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Labeled label="Status">
                                <select className={cn(selectCls, 'w-full')} value={draft.status} onChange={(e) => setDraft({ ...draft, status: e.target.value })}>
                                    <option value="">All statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="posted">Posted</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </Labeled>
                            <Labeled label="Item type">
                                <select className={cn(selectCls, 'w-full')} value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
                                    <option value="">All types</option>
                                    {catalogs.map((s) => (
                                        <option key={s} value={s}>
                                            {catalogLabel[s]}
                                        </option>
                                    ))}
                                </select>
                            </Labeled>
                            <Labeled label="TR number">
                                <Input className={cn(field, 'font-mono')} placeholder="TR0000…" value={draft.ref} onChange={(e) => setDraft({ ...draft, ref: e.target.value })} />
                            </Labeled>
                        </div>
                    )}

                    <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-border pt-4">
                        <Button type="submit" size="sm" variant="secondary" className="h-9 px-4">
                            Apply filter
                        </Button>
                        <Button type="button" size="sm" variant="ghost" className="h-9 border border-border" onClick={reset}>
                            Reset filter
                        </Button>
                        <button
                            type="button"
                            onClick={() => setShowMore((s) => !s)}
                            aria-expanded={showMore}
                            className="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px] font-semibold text-secondary hover:bg-accent"
                        >
                            <SlidersHorizontal className="size-3.5" />
                            {showMore ? 'Fewer filters' : 'More filters'}
                            {!showMore && moreActive > 0 && <span className="grid size-5 place-items-center rounded-full bg-secondary text-[11px] text-secondary-foreground">{moreActive}</span>}
                        </button>
                        {anyFilter > 0 && <span className="ml-auto text-xs text-muted-foreground">{anyFilter} {anyFilter === 1 ? 'filter' : 'filters'} applied</span>}
                    </div>
                </form>
            </section>

            <div className={cn('transition-opacity duration-200', loading && 'opacity-60')} aria-busy={loading}>
                {/* 2 — the headline numbers */}
                <section className={cn(panel, 'mb-5 grid divide-y divide-border overflow-hidden sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-[1.6fr_1fr_1fr_1fr_1.1fr] lg:divide-x')}>
                    <div className="bg-gradient-to-br from-[#f1f4fe] to-[#dbe4fc] px-5 py-4 sm:col-span-2 lg:col-span-1">
                        <p className="text-[13px] font-medium text-foreground/70">Total units ordered</p>
                        <p className="mt-1 text-4xl font-semibold leading-none tabular-nums">{nf.format(summary.units)}</p>
                        {unitsTotal > 0 ? (
                            <>
                                <div className="mt-3 flex h-2 gap-0.5" role="img" aria-label={catalogs.map((k) => `${catalogLabel[k]} ${nf.format(summary[catalogUnitsKey[k]])}`).join(', ')}>
                                    {catalogs.map(
                                        (k) =>
                                            summary[catalogUnitsKey[k]] > 0 && (
                                                <div key={k} className="first:rounded-l-[4px] last:rounded-r-[4px]" style={{ width: `${(summary[catalogUnitsKey[k]] / unitsTotal) * 100}%`, background: catalogColor[k] }} />
                                            ),
                                    )}
                                </div>
                                <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-foreground/75">
                                    {catalogs.map((k) => (
                                        <span key={k} className="inline-flex items-center gap-1.5">
                                            <Dot color={catalogColor[k]} /> {catalogLabel[k]} <strong className="tabular-nums">{nf.format(summary[catalogUnitsKey[k]])}</strong>
                                        </span>
                                    ))}
                                </p>
                            </>
                        ) : (
                            <p className="mt-3 text-xs text-foreground/70">Nothing ordered for this selection.</p>
                        )}
                    </div>
                    <Metric label="Orders (TRs)" value={summary.orders} />
                    <Metric label="Stores that ordered" value={summary.stores} />
                    <Metric label="Different products" value={summary.products} />
                    <div className="px-5 py-4">
                        <p className="text-[13px] text-muted-foreground">By status</p>
                        <ul className="mt-2 space-y-1.5">
                            {(['pending', 'posted', 'cancelled'] as const).map((k) => (
                                <li key={k} className="flex items-center justify-between text-[13px]">
                                    <StatusBadge status={k} />
                                    <span className={cn('font-semibold tabular-nums', summary.status[k] === 0 && 'text-muted-foreground/50')}>{summary.status[k]}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* 3 — the results */}
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" aria-label="Report view" className="inline-flex rounded-md border border-border bg-white p-0.5">
                        {(
                            [
                                ['detail', 'Order items'],
                                ['products', 'By product & store'],
                            ] as const
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={filters.view === key}
                                onClick={() => go({ view: key })}
                                className={cn(
                                    'rounded px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                                    filters.view === key ? 'bg-secondary text-secondary-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <p className="text-[13px] text-muted-foreground">
                        {filters.view === 'detail'
                            ? `${nf.format(detail?.total ?? 0)} order ${(detail?.total ?? 0) === 1 ? 'item' : 'items'}`
                            : `${nf.format(pivot?.meta?.total ?? 0)} ${(pivot?.meta?.total ?? 0) === 1 ? 'product' : 'products'} across ${pivot?.stores.length ?? 0} ${(pivot?.stores.length ?? 0) === 1 ? 'store' : 'stores'}`}
                    </p>
                </div>

                {filters.view === 'detail' && detail && <DetailTable detail={detail} filters={filters} sortBy={sortBy} />}
                {filters.view === 'products' && pivot && <PivotTable pivot={pivot} />}
            </div>
        </AppLayout>
    );
}

function DetailTable({ detail, filters, sortBy }: { detail: Paginated<DetailRow>; filters: Filters; sortBy: (key: string) => void }) {
    return (
        <section className={panel}>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[1100px] border-collapse">
                    <thead className="bg-secondary text-secondary-foreground">
                        <tr>
                            {columns.map((c) => {
                                const active = filters.sort === c.key;
                                const Icon = !active ? ArrowUpDown : filters.dir === 'asc' ? ArrowUp : ArrowDown;
                                return (
                                    <th key={c.key} className={cn(th, c.right && 'text-right')} aria-sort={active ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
                                        <button type="button" onClick={() => sortBy(c.key)} className="inline-flex items-center gap-1.5 uppercase hover:text-white">
                                            {c.label}
                                            <Icon className={cn('size-3', active ? 'opacity-100' : 'opacity-50')} />
                                        </button>
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {detail.data.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                    No order items match these filters.
                                </td>
                            </tr>
                        )}
                        {detail.data.map((r, i) => (
                            <tr key={`${r.order_id}-${r.product_code}-${i}`} className={cn('border-b border-border/70 odd:bg-white even:bg-[#f8f9fc] hover:bg-accent/60', r.status === 'cancelled' && 'text-muted-foreground')}>
                                <td className={cn(td, 'whitespace-nowrap')}>{formatDay(r.order_date)}</td>
                                <td className={cn(td, 'whitespace-nowrap font-mono')}>
                                    <Link href={`/orders/${r.order_id}`} className="text-secondary hover:underline">
                                        {r.number}
                                    </Link>
                                </td>
                                <td className={cn(td, 'font-medium')}>{r.store_code ?? '—'}</td>
                                <td className={cn(td, 'whitespace-nowrap')}>{r.store_name ?? '—'}</td>
                                <td className={cn(td, 'whitespace-nowrap')}>{r.product_code}</td>
                                <td className={cn(td, 'font-medium')}>{r.name}</td>
                                <td className={cn(td, 'whitespace-nowrap')}>{r.category}</td>
                                <td className={cn(td, 'whitespace-nowrap')}>
                                    <span className="inline-flex items-center gap-1.5">
                                        <Dot color={catalogColor[r.source]} />
                                        {catalogSingular[r.source]}
                                    </span>
                                </td>
                                <td className={cn(td, 'text-right font-semibold tabular-nums')}>{nf.format(r.quantity)}</td>
                                <td className={td}>
                                    <StatusBadge status={r.status} />
                                </td>
                                <td className={cn(td, 'whitespace-nowrap text-muted-foreground')}>{r.submitted_at ? formatDate(r.submitted_at) : '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pager from={detail.from} to={detail.to} total={detail.total} links={detail.links} noun="items" />
        </section>
    );
}

function PivotTable({ pivot }: { pivot: NonNullable<Props['pivot']> }) {
    return (
        <section className={panel}>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse" style={{ minWidth: 520 + pivot.stores.length * 110 }}>
                    <thead className="bg-secondary text-secondary-foreground">
                        <tr>
                            <th className={th}>SKU</th>
                            <th className={th}>Product</th>
                            <th className={th}>Item type</th>
                            {pivot.stores.map((s) => (
                                <th key={s.id} className={cn(th, 'text-right')} title={s.name}>
                                    {s.code}
                                </th>
                            ))}
                            <th className={cn(th, 'text-right')}>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pivot.rows.length === 0 && (
                            <tr>
                                <td colSpan={4 + pivot.stores.length} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                    No products match these filters.
                                </td>
                            </tr>
                        )}
                        {pivot.rows.map((r) => (
                            <tr key={`${r.source}-${r.product_code}`} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc] hover:bg-accent/60">
                                <td className={cn(td, 'whitespace-nowrap font-medium')}>{r.product_code}</td>
                                <td className={td}>
                                    {r.name}
                                    {r.category && <p className="text-xs text-muted-foreground">{r.category}</p>}
                                </td>
                                <td className={cn(td, 'whitespace-nowrap')}>
                                    <span className="inline-flex items-center gap-1.5">
                                        <Dot color={catalogColor[r.source]} />
                                        {catalogLabel[r.source]}
                                    </span>
                                </td>
                                {pivot.stores.map((s) => (
                                    <td key={s.id} className={cn(td, 'text-right tabular-nums')}>
                                        {r.by_store[s.id] ? nf.format(r.by_store[s.id]) : <span className="text-muted-foreground/50">–</span>}
                                    </td>
                                ))}
                                <td className={cn(td, 'text-right font-semibold tabular-nums')}>{nf.format(r.total)}</td>
                            </tr>
                        ))}
                    </tbody>
                    {pivot.rows.length > 0 && (
                        <tfoot>
                            <tr className="border-t-2 border-border bg-[#f2f4f8] font-semibold">
                                <td className={td} colSpan={3}>
                                    Total of all filtered products
                                </td>
                                {pivot.stores.map((s) => (
                                    <td key={s.id} className={cn(td, 'text-right tabular-nums')}>
                                        {nf.format(pivot.totals.by_store[s.id] ?? 0)}
                                    </td>
                                ))}
                                <td className={cn(td, 'text-right tabular-nums')}>{nf.format(pivot.totals.total)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
            {pivot.meta && <Pager from={pivot.meta.from} to={pivot.meta.to} total={pivot.meta.total} links={pivot.meta.links} noun="products" />}
        </section>
    );
}

function Pager({ from, to, total, links, noun }: { from: number | null; to: number | null; total: number; links: Paginated<unknown>['links']; noun: string }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground">
            <p>{total === 0 ? 'No entries' : `Showing ${from} to ${to} of ${total} ${noun}`}</p>
            {links.length > 3 && (
                <div className="flex flex-wrap gap-1">
                    {links.map((l, i) => {
                        const cls = 'min-w-8 rounded border px-2 py-1 text-center text-xs font-medium';
                        return l.url ? (
                            <Link
                                key={i}
                                href={l.url}
                                preserveScroll
                                preserveState
                                className={cn(cls, l.active ? 'border-secondary bg-secondary text-secondary-foreground' : 'border-border bg-white hover:bg-muted')}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ) : (
                            <span key={i} className={cn(cls, 'border-transparent opacity-40')} dangerouslySetInnerHTML={{ __html: l.label }} />
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Metric({ label, value }: { label: string; value: number }) {
    return (
        <div className="px-5 py-4">
            <p className="text-[13px] text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{nf.format(value)}</p>
        </div>
    );
}

function Dot({ color }: { color: string }) {
    return <span className="inline-block size-2.5 shrink-0 rounded-[3px]" style={{ background: color }} />;
}

function Labeled({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <label className="block space-y-1">
            <span className="text-xs font-semibold text-muted-foreground">{label}</span>
            {children}
        </label>
    );
}
