import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';

import CatalogUnits from '@/components/catalog-units';
import NewOrderButton from '@/components/new-order-button';
import { StatusBadge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import OrderCards from '@/components/order-cards';
import { catalogLabel, catalogUnitsKey, catalogs } from '@/lib/charts';
import { formatDay } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource, OrderStatus, OrderSummary, Paginated, SharedProps, StoreRef } from '@/types';

interface Props {
    orders: Paginated<OrderSummary>;
    status: OrderStatus | null;
    catalog: ItemSource | null;
    store: number | null;
    search: string;
    stores: StoreRef[];
}

const filters: { key: OrderStatus | null; label: string }[] = [
    { key: null, label: 'All' },
    { key: 'pending', label: 'Pending' },
    { key: 'posted', label: 'Posted' },
    { key: 'cancelled', label: 'Cancelled' },
];

export default function Orders({ orders, status, catalog, store, search, stores }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const isAdmin = auth.user.role === 'admin';
    const [query, setQuery] = useState(search);

    // one place builds the URL so changing one filter never drops the others
    const visit = (next: { status?: OrderStatus | null; catalog?: ItemSource | null; store?: number | null; search?: string }) => {
        const f = { status, catalog, store, search, ...next };
        router.get(
            '/orders',
            { status: f.status ?? undefined, catalog: f.catalog ?? undefined, store: f.store ?? undefined, search: f.search || undefined },
            { preserveState: true, replace: true },
        );
    };

    useEffect(() => {
        if (query === search) return;
        const t = setTimeout(() => visit({ search: query }), 300);
        return () => clearTimeout(t);
    }, [query]);

    const columns = (isAdmin ? 5 : 4) + catalogs.length;

    return (
        <AppLayout title={isAdmin ? 'Orders (TRs)' : 'My Orders'} actions={isAdmin ? undefined : <NewOrderButton />}>
            <div className={panel}>
                <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <div className="flex flex-wrap gap-2">
                        {filters.map((f) => (
                            <button
                                key={f.label}
                                type="button"
                                onClick={() => visit({ status: f.key })}
                                aria-pressed={status === f.key}
                                className={cn(
                                    'rounded-md border px-3 py-1.5 text-[13px] font-semibold',
                                    status === f.key
                                        ? 'border-secondary bg-secondary text-secondary-foreground'
                                        : 'border-border bg-white text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>

                    {isAdmin && (
                        <select
                            aria-label="Filter by store"
                            value={store ?? ''}
                            onChange={(e) => visit({ store: e.target.value ? Number(e.target.value) : null })}
                            className={selectCls}
                        >
                            <option value="">All stores</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.code} — {s.name}
                                </option>
                            ))}
                        </select>
                    )}

                    <select
                        aria-label="Filter by catalog"
                        value={catalog ?? ''}
                        onChange={(e) => visit({ catalog: (e.target.value || null) as ItemSource | null })}
                        className={selectCls}
                    >
                        <option value="">All catalogs</option>
                        {catalogs.map((s) => (
                            <option key={s} value={s}>
                                With {catalogLabel[s]}
                            </option>
                        ))}
                    </select>

                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={isAdmin ? 'TR no., store or staff' : 'TR no. or staff name'}
                            aria-label="Search orders"
                            className={cn(field, 'pl-8')}
                        />
                    </div>
                </div>

                <OrderCards orders={orders.data} showStore={isAdmin} empty={search || status || catalog || store ? 'No orders match.' : 'No orders yet.'} />

                <div className="overflow-x-auto max-md:hidden">
                    <table className="w-full min-w-[900px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>TR</th>
                                {isAdmin && <th className={th}>Store</th>}
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
                            {orders.data.length === 0 && (
                                <tr>
                                    <td colSpan={columns} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                        {search || status || catalog || store ? 'No orders match.' : 'No orders yet.'}
                                    </td>
                                </tr>
                            )}
                            {orders.data.map((o) => (
                                <tr
                                    key={o.id}
                                    className={cn(
                                        'border-b border-border/70 odd:bg-white even:bg-[#f8f9fc] hover:bg-accent/60',
                                        o.status === 'cancelled' && 'text-muted-foreground',
                                    )}
                                >
                                    {/* only the TR number opens the order, clicking elsewhere on the row does nothing */}
                                    <td className={cn(td, 'font-mono font-medium')}>
                                        <Link href={`/orders/${o.id}`} className="text-secondary hover:underline">
                                            {o.number}
                                        </Link>
                                    </td>
                                    {isAdmin && (
                                        <td className={cn(td, 'whitespace-nowrap')}>
                                            {o.store ? (
                                                <>
                                                    <span className="font-medium">{o.store.code}</span> <span className="text-muted-foreground">{o.store.name}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                    )}
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

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground">
                    <p>{orders.total === 0 ? 'No entries' : `Showing ${orders.from} to ${orders.to} of ${orders.total} entries`}</p>
                    {orders.links.length > 3 && (
                        <div className="flex flex-wrap gap-1">
                            {orders.links.map((l, i) => {
                                const cls = 'min-w-8 rounded border px-2 py-1 text-center text-xs font-medium';
                                return l.url ? (
                                    <Link
                                        key={i}
                                        href={l.url}
                                        preserveScroll
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
            </div>
        </AppLayout>
    );
}
