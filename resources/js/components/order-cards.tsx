import { Link } from '@inertiajs/react';

import { StatusBadge } from '@/components/ui/badge';
import { catalogColor, catalogLabel, catalogUnitsKey, catalogs, nf } from '@/lib/charts';
import { formatDay } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { OrderSummary } from '@/types';

/** Phone layout for an orders list: one card per order instead of a wide table (shown below the md breakpoint only). */
export default function OrderCards({ orders, showStore, empty }: { orders: OrderSummary[]; showStore: boolean; empty: string }) {
    if (orders.length === 0) return <p className="px-4 py-10 text-center text-sm text-muted-foreground md:hidden">{empty}</p>;

    return (
        <ul className="divide-y divide-border/70 md:hidden">
            {orders.map((o) => {
                const parts = catalogs.filter((s) => o[catalogUnitsKey[s]] > 0);

                return (
                    <li key={o.id} className={cn('px-4 py-3', o.status === 'cancelled' && 'text-muted-foreground')}>
                        <div className="flex items-start justify-between gap-3">
                            {/* only the TR number opens the order */}
                            <Link href={`/orders/${o.id}`} className="py-0.5 font-mono text-[14px] font-semibold text-secondary">
                                {o.number}
                            </Link>
                            <StatusBadge status={o.status} />
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {showStore && o.store ? `${o.store.name} · ` : ''}
                            {formatDay(o.order_date)} · {o.ordered_by}
                        </p>
                        {parts.length > 0 && (
                            <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                {parts.map((s) => (
                                    <span key={s} className="inline-flex items-center gap-1.5">
                                        <span className="size-2 rounded-[2px]" style={{ background: catalogColor[s] }} />
                                        {catalogLabel[s]} <strong className="tabular-nums">{nf.format(o[catalogUnitsKey[s]])}</strong>
                                    </span>
                                ))}
                            </p>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
