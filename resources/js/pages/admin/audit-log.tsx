import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Search } from 'lucide-react';

import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Entry {
    key: string;
    at: string;
    event: string;
    by: string | null;
    store: string | null;
    order: { id: number; number: string } | null;
    subject: string | null;
    ip: string | null;
    note: string | null;
    deadline: { previous: string | null; new: string; applies_on: string | null } | null;
}

interface Filters {
    type: string | null;
    store: number | null;
    from: string | null;
    to: string | null;
    search: string;
    per_page: number;
}

interface Props {
    entries: Paginated<Entry>;
    filters: Filters;
    stores: { id: number; code: string; name: string }[];
    event_labels: Record<string, { label: string; group: string }>;
}

const PER_PAGE = [10, 25, 50, 100];

const events: Record<string, { label: string; cls: string }> = {
    placed: { label: 'Order placed', cls: 'bg-[#e8ecfb] text-[#1f3aa8]' },
    posted: { label: 'Order posted', cls: 'bg-[#dff3e6] text-[#1d6b3a]' },
    cancelled: { label: 'Order cancelled', cls: 'bg-[#fde4e0] text-[#a3261a]' },
    reset: { label: 'Order reset', cls: 'bg-[#fff1d6] text-[#8a5200]' },
    send_requested: { label: 'ECPOS resend', cls: 'bg-[#eef1f8] text-[#3a4270]' },
    sent: { label: 'Sent to ECPOS', cls: 'bg-[#dff3e6] text-[#1d6b3a]' },
    send_failed: { label: 'ECPOS failed', cls: 'bg-[#fde4e0] text-[#a3261a]' },
    deadline: { label: 'Deadline changed', cls: 'bg-[#f0e6fb] text-[#5b2a9c]' },
};

/** Badge colour for the sign-in / admin activity, by what kind of thing it was. */
const groupCls: Record<string, string> = {
    'Sign-ins': 'bg-[#e6f4f7] text-[#155e75]',
    Passwords: 'bg-[#fff1d6] text-[#8a5200]',
    Users: 'bg-[#f0e6fb] text-[#5b2a9c]',
    Stores: 'bg-[#e8ecfb] text-[#1f3aa8]',
    Catalog: 'bg-[#eef1f8] text-[#3a4270]',
};
const alarming = ['login_failed', 'login_blocked', 'login_deactivated', 'user_deactivated', 'manager_removed'];

export default function AuditLog({ entries, filters, stores, event_labels }: Props) {
    const [query, setQuery] = useState(filters.search);

    const visit = (next: Partial<Filters>) => {
        const f = { ...filters, ...next };
        router.get(
            '/admin/audit-log',
            { type: f.type || undefined, store: f.store || undefined, from: f.from || undefined, to: f.to || undefined, search: f.search || undefined, per_page: f.per_page === 50 ? undefined : f.per_page },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (query === filters.search) return;
        const t = setTimeout(() => visit({ search: query }), 300);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const filtered = !!(filters.type || filters.store || filters.from || filters.to || filters.search);

    return (
        <AppLayout title="Audit Log">
            <p className="mb-4 text-[13px] text-muted-foreground">Everything that happened to orders and to the ordering deadline, newest first. This page is read-only.</p>

            <div className={panel}>
                <div className="flex flex-wrap items-end gap-3 border-b border-border p-3">
                    <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                        What happened
                        <select className={selectCls} value={filters.type ?? ''} onChange={(e) => visit({ type: e.target.value || null })}>
                            <option value="">Everything</option>
                            <optgroup label="Orders">
                                {Object.entries(events).map(([key, e]) => (
                                    <option key={key} value={key}>
                                        {e.label}
                                    </option>
                                ))}
                            </optgroup>
                            {[...new Set(Object.values(event_labels).map((e) => e.group))].map((group) => (
                                <optgroup key={group} label={group}>
                                    {Object.entries(event_labels)
                                        .filter(([, e]) => e.group === group)
                                        .map(([key, e]) => (
                                            <option key={key} value={key}>
                                                {e.label}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                        Store
                        <select className={selectCls} value={filters.store ?? ''} onChange={(e) => visit({ store: e.target.value ? Number(e.target.value) : null })}>
                            <option value="">All stores</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.code} — {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                        From
                        <input type="date" className={selectCls} value={filters.from ?? ''} onChange={(e) => visit({ from: e.target.value || null })} />
                    </label>
                    <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                        To
                        <input type="date" className={selectCls} value={filters.to ?? ''} onChange={(e) => visit({ to: e.target.value || null })} />
                    </label>
                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search TR, name or email…" aria-label="Search TR number, name or email" className={cn(field, 'pl-8')} />
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr className="bg-secondary text-secondary-foreground">
                                <th className={th}>When</th>
                                <th className={th}>What happened</th>
                                <th className={th}>Order / detail</th>
                                <th className={th}>Store</th>
                                <th className={th}>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                        {filtered ? 'Nothing matches these filters.' : 'Nothing has been recorded yet.'}
                                    </td>
                                </tr>
                            )}
                            {entries.data.map((e) => {
                                const known = event_labels[e.event];
                                const ev = events[e.event] ?? (known ? { label: known.label, cls: alarming.includes(e.event) ? 'bg-[#fde4e0] text-[#a3261a]' : (groupCls[known.group] ?? 'bg-muted text-foreground') } : { label: e.event, cls: 'bg-muted text-foreground' });
                                return (
                                    <tr key={e.key} className="border-b border-border/70 align-top odd:bg-white even:bg-[#f8f9fc]">
                                        <td className={cn(td, 'whitespace-nowrap')}>{formatDate(e.at)}</td>
                                        <td className={cn(td, 'whitespace-nowrap')}>
                                            <span className={cn('inline-block rounded px-2 py-0.5 text-xs font-semibold', ev.cls)}>{ev.label}</span>
                                        </td>
                                        <td className={td}>
                                            {e.order && (
                                                <Link href={`/orders/${e.order.id}`} className="font-mono text-[12.5px] font-medium text-secondary hover:underline">
                                                    {e.order.number}
                                                </Link>
                                            )}
                                            {e.deadline && (
                                                <span className="inline-flex flex-wrap items-center gap-1.5">
                                                    {e.deadline.previous ? formatTime(e.deadline.previous) : 'none'} <ArrowRight className="size-3 text-muted-foreground" />
                                                    <span className="font-semibold">{formatTime(e.deadline.new)}</span>
                                                    {e.deadline.applies_on && <span className="text-muted-foreground">for {e.deadline.applies_on}</span>}
                                                </span>
                                            )}
                                            {e.subject && <p className="text-[13px] font-medium">{e.subject}</p>}
                                            {e.note && <p className="mt-0.5 text-xs text-muted-foreground">{e.note}</p>}
                                        </td>
                                        <td className={td}>{e.store ?? <span className="text-muted-foreground/50">—</span>}</td>
                                        <td className={cn(td, 'whitespace-nowrap')}>
                                            {e.by ?? <span className="text-muted-foreground">{e.ip ? 'Not signed in' : 'System'}</span>}
                                            {e.ip && <p className="font-mono text-[11px] text-muted-foreground">{e.ip}</p>}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <Pagination data={entries} perPage={{ value: filters.per_page, options: PER_PAGE, onChange: (n) => visit({ per_page: n }) }} />
            </div>
        </AppLayout>
    );
}
