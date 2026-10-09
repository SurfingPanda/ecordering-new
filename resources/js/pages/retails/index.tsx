import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { Archive, ArchiveRestore, ArrowDown, ArrowUp, ArrowUpDown, CloudDownload, FileSpreadsheet, PackageSearch, Plus, RefreshCw, Search, X } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import ItemFormDialog from '@/components/item-form-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { catalogColor, catalogLabel, catalogs, nf } from '@/lib/charts';
import { formatDate } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import { retailGroupLabel, type Item, type ItemSource, type Paginated, type RetailGroup, type SharedProps } from '@/types';

interface Filters {
    source: ItemSource;
    group: RetailGroup | null;
    search: string;
    sort: string;
    dir: 'asc' | 'desc';
}

interface Props {
    items: Paginated<Item & { archived_at: string | null }>;
    /** 'active' = the live catalog; 'archived' = the admin-only Archived Products page. */
    view: 'active' | 'archived';
    basePath: string;
    filters: Filters;
    categories: Record<ItemSource, string[]>;
    counts: Record<ItemSource, number>;
    ecpos: { configured: boolean; last_sync: string | null } | null;
}

const sources: ItemSource[] = catalogs;

const baseColumns: { key: string; label: string }[] = [
    { key: 'product_code', label: 'Product Code' },
    { key: 'description', label: 'Description' },
    { key: 'barcode', label: 'Barcode' },
    { key: 'category', label: 'Category' },
    { key: 'retail_group', label: 'Retail Group' },
];

export default function Retails({ items, view, basePath, filters, categories, counts, ecpos }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const isAdmin = auth.user.role === 'admin';
    const archivedView = view === 'archived';
    const columns = archivedView ? [...baseColumns, { key: 'archived_at', label: 'Archived on' }] : baseColumns;

    const [query, setQuery] = useState(filters.search);
    const [adding, setAdding] = useState(false);

    // ---- refresh: tell the user whether items were added (or archived) since they last looked ----
    type Known = { id: number; source: ItemSource; product_code: string; description: string };
    const baseline = useRef<Promise<Known[] | null> | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const fetchSnapshot = async (): Promise<Known[] | null> => {
        try {
            const res = await fetch('/retails/snapshot', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) return null;
            return (await res.json()).items;
        } catch {
            return null;
        }
    };
    useEffect(() => {
        if (!archivedView) baseline.current = fetchSnapshot();
    }, [archivedView]);

    // ---- admin: pull the BW Products catalog from ECPOS ----
    const [syncing, setSyncing] = useState(false);
    const runSync = async () => {
        setSyncing(true);
        const common = { confirmButtonText: 'OK', showCloseButton: true, allowOutsideClick: false, customClass: { confirmButton: 'swal-confirm' } };
        // a pop-up with a moving progress bar stays up for as long as the sync runs (it can't be dismissed meanwhile)
        void Swal.fire({
            title: 'Syncing from ECPOS…',
            html: '<p style="margin:0 0 .9rem;color:#6b7280;font-size:.95rem">Fetching the catalog and updating items. Please keep this page open.</p><div class="sync-progress"><span></span></div>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading(),
        });
        const started = Date.now();
        try {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const res = await fetch('/retails/ecpos-sync', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
            const body = await res.json().catch(() => ({}));
            // even a very quick sync keeps the progress pop-up up long enough to be seen
            await new Promise((r) => setTimeout(r, Math.max(0, 900 - (Date.now() - started))));
            if (!res.ok) {
                void Swal.fire({ ...common, icon: 'error', title: 'Sync failed', text: body.message ?? 'Something went wrong. Nothing was changed.' });
                return;
            }
            await new Promise<void>((done) => router.reload({ only: ['items', 'counts', 'categories', 'ecpos'], onFinish: () => done() }));
            const rows = [
                ['New items added', body.added],
                ['Items updated', body.updated],
                ['Items restored', body.restored],
                ['Items archived (no longer in ECPOS)', body.archived],
                ['Already up to date', body.unchanged],
            ] as const;
            const changed = body.added + body.updated + body.restored + body.archived > 0;
            void Swal.fire({
                ...common,
                icon: changed ? 'success' : 'info',
                title: changed ? 'Catalog synced' : 'Already up to date',
                html: `<table style="margin:.5rem auto 0;text-align:left;font-size:.95rem">${rows.map(([k, v]) => `<tr><td style="padding:2px 14px 2px 0;color:#6b7280">${k}</td><td style="font-weight:600;text-align:right">${v}</td></tr>`).join('')}</table>`,
            });
        } catch {
            void Swal.fire({ ...common, icon: 'error', title: 'Sync failed', text: 'Couldn’t reach the server. Nothing was changed.' });
        } finally {
            setSyncing(false);
        }
    };
    const syncFromEcpos = () =>
        confirm({
            title: 'Sync BW Products from ECPOS?',
            description: 'New items are added, changed names and groups are updated, and items ECPOS no longer lists are archived (never deleted). Items in the Merchandise and BW Rejects groups go to their own catalogs. Warehouse items and items you added by hand are not touched.',
            confirmLabel: 'Sync now',
            onConfirm: runSync,
        });

    const refresh = async () => {
        setRefreshing(true);
        try {
            const [before, after] = await Promise.all([baseline.current ?? Promise.resolve(null), fetchSnapshot()]);
            if (!after) {
                void Swal.fire({ icon: 'error', title: 'Couldn’t refresh', text: 'Please check your connection and try again.', confirmButtonText: 'OK', showCloseButton: true, allowOutsideClick: false, customClass: { confirmButton: 'swal-confirm' } });
                return;
            }
            baseline.current = Promise.resolve(after);
            await new Promise<void>((done) => router.reload({ only: ['items', 'counts', 'categories'], onFinish: () => done() }));

            const had = new Set((before ?? after).map((i) => i.id));
            const nowIds = new Set(after.map((i) => i.id));
            const added = after.filter((i) => !had.has(i.id));
            const removed = (before ?? []).filter((i) => !nowIds.has(i.id));
            const list = (rows: Known[]) =>
                `<ul style="text-align:left;margin:.75rem auto 0;max-width:22rem;padding-left:1.1rem;list-style:disc;font-size:.9rem">${rows
                    .slice(0, 8)
                    .map((i) => `<li><b>${i.product_code.replace(/</g, '&lt;')}</b> — ${i.description.replace(/</g, '&lt;')} <span style="color:#6b7280">(${catalogLabel[i.source]})</span></li>`)
                    .join('')}${rows.length > 8 ? `<li>…and ${rows.length - 8} more</li>` : ''}</ul>`;

            const common = { confirmButtonText: 'OK', showCloseButton: true, allowOutsideClick: false, customClass: { confirmButton: 'swal-confirm' } };
            if (added.length > 0) {
                void Swal.fire({ ...common, icon: 'success', title: added.length === 1 ? '1 new item added' : `${added.length} new items added`, html: `The list is up to date.${list(added)}` });
            } else if (removed.length > 0) {
                void Swal.fire({ ...common, icon: 'info', title: 'No new items', html: `${removed.length === 1 ? '1 item was' : `${removed.length} items were`} archived, so the list was updated.${list(removed)}` });
            } else {
                void Swal.fire({ ...common, icon: 'info', title: 'No new items', text: 'Nothing has been added since you last checked.' });
            }
        } finally {
            setRefreshing(false);
        }
    };    const [selected, setSelected] = useState<Set<number>>(new Set());

    // a new page / filter / sort shows different rows, so start the selection again
    const rowKey = items.data.map((i) => i.id).join(',');
    useEffect(() => setSelected(new Set()), [rowKey]);

    const pageIds = items.data.map((i) => i.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
    const someSelected = selected.size > 0 && !allSelected;

    const toggleAll = () => setSelected(allSelected ? new Set() : new Set(pageIds));
    const toggleOne = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });

    const [confirm, confirmDialog] = useConfirm();

    /** Archive (from the catalog) or restore (from Archived Products) the ticked items. */
    const changeSelected = () => {
        const n = selected.size;
        const ids = [...selected];
        const chosen = items.data.filter((i) => selected.has(i.id));
        const noun = n === 1 ? 'item' : 'items';

        const list = chosen.length > 0 && (
            <ul className="mb-2 max-h-28 list-disc space-y-0.5 overflow-y-auto pl-4">
                {chosen.slice(0, 6).map((i) => (
                    <li key={i.id}>
                        <span className="font-medium">{i.product_code}</span> — {i.description}
                    </li>
                ))}
                {chosen.length > 6 && <li>…and {chosen.length - 6} more</li>}
            </ul>
        );

        confirm(
            archivedView
                ? {
                      title: `Restore ${n} ${noun}?`,
                      description: 'They will appear in the catalog again and stores will be able to order them.',
                      note: list || undefined,
                      confirmLabel: n === 1 ? 'Restore item' : `Restore ${n} items`,
                      onConfirm: () => router.post('/retails/restore', { ids }, { preserveScroll: true, onSuccess: () => setSelected(new Set()) }),
                  }
                : {
                      title: `Archive ${n} ${noun}?`,
                      description: 'Archived items are hidden from the catalog and stores can no longer order them. Nothing is deleted — you can restore them from Archived Products.',
                      note: (
                          <>
                              {list}
                              Past orders are not affected.
                          </>
                      ),
                      confirmLabel: n === 1 ? 'Archive item' : `Archive ${n} items`,
                      onConfirm: () => router.post('/retails/archive', { ids }, { preserveScroll: true, onSuccess: () => setSelected(new Set()) }),
                  },
        );
    };

    const visit = (next: Partial<Filters>) => {
        const f = { ...filters, ...next };
        router.get(
            basePath,
            { source: f.source, group: f.group ?? undefined, search: f.search || undefined, sort: f.sort, dir: f.dir },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        if (query === filters.search) return;
        const t = setTimeout(() => visit({ search: query }), 300);
        return () => clearTimeout(t);
    }, [query]);

    // Excel export of what the filters currently select (all pages), for the catalog or the archive being viewed
    const exportHref =
        '/retails/export?' +
        new URLSearchParams({
            source: filters.source,
            ...(filters.group ? { group: filters.group } : {}),
            ...(filters.search ? { search: filters.search } : {}),
            sort: filters.sort,
            dir: filters.dir,
            ...(archivedView ? { archived: '1' } : {}),
        }).toString();

    const sortBy = (key: string) => visit({ sort: key, dir: filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc' });

    const filtered = filters.search !== '' || filters.group !== null;
    const clearFilters = () => {
        setQuery('');
        router.get(basePath, { source: filters.source }, { preserveScroll: true, replace: true });
    };

    const total = counts[filters.source];
    const noun = archivedView ? 'archived' : 'in the catalog';

    return (
        <AppLayout
            title={archivedView ? 'Archived Products' : isAdmin ? 'Product Catalog' : catalogLabel[filters.source]}
            actions={
                isAdmin && !archivedView ? (
                    <Button size="sm" variant="secondary" onClick={() => setAdding(true)}>
                        <Plus /> Add item
                    </Button>
                ) : undefined
            }
        >
            {archivedView && (
                <p className="mb-4 max-w-2xl text-[13px] text-muted-foreground">
                    Archived items are hidden from the catalog and can’t be ordered. Their past orders are unchanged. Tick items and choose <strong>Restore</strong> to bring them back.
                </p>
            )}

            {/* what you're looking at */}
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="flex items-center gap-2.5 text-xl font-semibold leading-tight">
                        <span className="size-3 rounded-[4px]" style={{ background: catalogColor[filters.source] }} aria-hidden="true" />
                        {catalogLabel[filters.source]}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {filtered ? (
                            <>
                                <strong className="text-foreground">{nf.format(items.total)}</strong> of {nf.format(total)} {total === 1 ? 'item' : 'items'} match your filters
                            </>
                        ) : (
                            <>
                                <strong className="text-foreground">{nf.format(total)}</strong> {total === 1 ? 'item' : 'items'} {noun}
                            </>
                        )}
                    </p>
                </div>

                {isAdmin && (
                    <div className="flex flex-wrap items-center gap-2">
                        {!archivedView && ecpos?.configured && (
                            <div className="flex items-center gap-2">
                                {ecpos.last_sync && <span className="hidden text-xs text-muted-foreground sm:inline">Last synced {formatDate(ecpos.last_sync)}</span>}
                                <Button size="sm" variant="ghost" className="border border-border bg-white" onClick={syncFromEcpos} disabled={syncing}>
                                    <CloudDownload className={cn('text-secondary', syncing && 'animate-pulse')} /> {syncing ? 'Syncing…' : 'Sync from ECPOS'}
                                </Button>
                            </div>
                        )}
                        <Button asChild size="sm" variant="ghost" className="border border-border bg-white">
                            <a href={exportHref} download>
                                <FileSpreadsheet className="text-[#16683a]" /> Export to Excel
                            </a>
                        </Button>
                    </div>
                )}
            </div>

            <div className={panel}>
                {/* switch catalog, then narrow it down */}
                <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <div role="tablist" aria-label="Catalog" className="no-scrollbar inline-flex max-w-full overflow-x-auto rounded-md border border-border bg-[#f2f4f8] p-0.5">
                        {sources.map((s) => {
                            const active = filters.source === s;
                            return (
                                <button
                                    key={s}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => router.get(basePath, { source: s }, { replace: true })}
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-2 rounded px-3.5 py-2 text-[13px] font-semibold transition-colors sm:py-1.5',
                                        active ? 'bg-secondary text-secondary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {catalogLabel[s]}
                                    <span className={cn('rounded-full px-1.5 text-[11px] tabular-nums', active ? 'bg-white/20' : 'bg-white text-muted-foreground')}>{nf.format(counts[s])}</span>
                                </button>
                            );
                        })}
                    </div>

                    {!archivedView && (
                        <button
                            type="button"
                            onClick={refresh}
                            disabled={refreshing}
                            title="Refresh — check for newly added items"
                            aria-label="Refresh the item list"
                            className="grid size-9 place-items-center rounded-md border border-border bg-white text-muted-foreground transition-colors hover:border-secondary hover:text-secondary disabled:opacity-60"
                        >
                            <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} />
                        </button>
                    )}

                    <div className="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
                        <select
                            aria-label="Retail group"
                            value={filters.group ?? ''}
                            onChange={(e) => visit({ group: (e.target.value || null) as RetailGroup | null })}
                            className={selectCls}
                        >
                            <option value="">All retail groups</option>
                            <option value="regular_product">Regular Product</option>
                            <option value="non_product">Non-product</option>
                        </select>
                        <div className="relative w-full sm:w-64">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search code, name, barcode…" aria-label="Search items" className={cn(field, 'px-8')} />
                            {query && (
                                <button
                                    type="button"
                                    onClick={() => setQuery('')}
                                    aria-label="Clear search"
                                    className="absolute right-1.5 top-1/2 grid size-6 -translate-y-1/2 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {isAdmin && selected.size > 0 && (
                    <div role="status" className="flex flex-wrap items-center gap-3 border-b border-border bg-accent px-4 py-2 text-[13px]">
                        <span className="font-semibold">
                            {selected.size} {selected.size === 1 ? 'item' : 'items'} selected
                        </span>
                        <Button size="sm" variant="ghost" className="h-8 border border-secondary/40 bg-white text-secondary hover:bg-white hover:text-secondary" onClick={changeSelected}>
                            {archivedView ? <ArchiveRestore /> : <Archive />} {archivedView ? 'Restore selected' : 'Archive selected'}
                        </Button>
                        <button type="button" onClick={() => setSelected(new Set())} className="font-medium text-secondary hover:underline">
                            Clear selection
                        </button>
                    </div>
                )}

                <div className="no-scrollbar max-h-[calc(100vh-330px)] min-h-[280px] overflow-auto">
                    <table className="w-full border-collapse max-md:block md:min-w-[760px]">
                        <thead className="sticky top-0 z-10 bg-secondary text-secondary-foreground max-md:hidden">
                            <tr>
                                {isAdmin && (
                                    <th className={cn(th, 'w-10')}>
                                        <Checkbox
                                            checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                                            onCheckedChange={toggleAll}
                                            aria-label="Select all items on this page"
                                            disabled={pageIds.length === 0}
                                            className="border-white/70 data-[state=checked]:border-white data-[state=checked]:bg-white data-[state=checked]:text-secondary data-[state=indeterminate]:border-white data-[state=indeterminate]:bg-white data-[state=indeterminate]:text-secondary"
                                        />
                                    </th>
                                )}
                                {columns.map((c) => {
                                    const active = filters.sort === c.key;
                                    const Icon = !active ? ArrowUpDown : filters.dir === 'asc' ? ArrowUp : ArrowDown;
                                    return (
                                        <th key={c.key} className={th} aria-sort={active ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
                                            <button type="button" onClick={() => sortBy(c.key)} className="inline-flex items-center gap-1.5 uppercase hover:text-white">
                                                {c.label}
                                                <Icon className={cn('size-3', active ? 'opacity-100' : 'opacity-50')} />
                                            </button>
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody className="max-md:block">
                            {items.data.length === 0 && (
                                <tr>
                                    <td colSpan={columns.length + (isAdmin ? 1 : 0)} className="px-5 py-16 text-center">
                                        <PackageSearch className="mx-auto size-9 text-muted-foreground/50" />
                                        <p className="mt-3 text-sm font-semibold">{filtered ? 'No items match your filters' : archivedView ? 'Nothing is archived here' : 'No items yet'}</p>
                                        <p className="mt-1 text-[13px] text-muted-foreground">
                                            {filtered ? 'Try a different search, or clear the filters.' : archivedView ? 'Items you archive will show up on this page.' : isAdmin ? 'Use “Add item” to create the first one.' : 'Nothing has been added to this catalog yet.'}
                                        </p>
                                        {filtered && (
                                            <Button size="sm" variant="ghost" className="mt-4 border border-border" onClick={clearFilters}>
                                                Clear filters
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            )}
                            {items.data.map((item) => (
                                <tr
                                    key={item.id}
                                    className={cn('border-b border-border/70 hover:bg-accent/60 max-md:grid max-md:items-center max-md:gap-x-3 max-md:px-3 max-md:py-2.5', isAdmin ? 'max-md:grid-cols-[auto_1fr]' : 'max-md:grid-cols-1', selected.has(item.id) ? 'bg-accent' : 'odd:bg-white even:bg-[#f8f9fc]')}
                                >
                                    {isAdmin && (
                                        <td className={cn(td, 'w-10 py-2.5 max-md:col-start-1 max-md:row-span-2 max-md:row-start-1 max-md:p-0')}>
                                            <Checkbox checked={selected.has(item.id)} onCheckedChange={() => toggleOne(item.id)} aria-label={`Select ${item.product_code}`} />
                                        </td>
                                    )}
                                    <td className={cn(td, 'whitespace-nowrap py-2.5 font-mono text-[12.5px] font-medium max-md:row-start-2 max-md:whitespace-normal max-md:p-0 max-md:text-xs max-md:font-normal max-md:text-muted-foreground', isAdmin ? 'max-md:col-start-2' : 'max-md:col-start-1')}>
                                        {item.product_code}
                                        {item.category && <span className="font-sans md:hidden"> · {item.category}</span>}
                                    </td>
                                    <td className={cn(td, 'py-2.5 text-sm font-medium max-md:row-start-1 max-md:p-0 max-md:text-[14px] max-md:leading-snug', isAdmin ? 'max-md:col-start-2' : 'max-md:col-start-1')}>{item.description}</td>
                                    <td className={cn(td, 'whitespace-nowrap py-2.5 font-mono text-[12.5px] tracking-wide text-muted-foreground max-md:hidden')}>
                                        {item.barcode ?? <span className="font-sans tracking-normal text-muted-foreground/50">No barcode</span>}
                                    </td>
                                    <td className={cn(td, 'whitespace-nowrap py-2.5 max-md:hidden')}>
                                        {item.category ? (
                                            <span className="inline-block rounded-full bg-[#eef1f8] px-2.5 py-0.5 text-xs font-medium text-[#3a4270]">{item.category}</span>
                                        ) : (
                                            <span className="text-muted-foreground/50">—</span>
                                        )}
                                    </td>
                                    <td className={cn(td, 'whitespace-nowrap py-2.5 max-md:hidden')}>
                                        <span
                                            className={cn(
                                                'inline-block rounded px-2 py-0.5 text-xs font-semibold',
                                                item.retail_group === 'non_product' ? 'bg-[#fff1d6] text-[#8a5200]' : 'bg-[#e8ecfb] text-[#1f3aa8]',
                                            )}
                                        >
                                            {retailGroupLabel[item.retail_group]}
                                        </span>
                                    </td>
                                    {archivedView && <td className={cn(td, 'whitespace-nowrap py-2.5 text-muted-foreground max-md:hidden')}>{item.archived_at ? formatDate(item.archived_at) : '—'}</td>}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pager data={items} />
            </div>

            {confirmDialog}
            {isAdmin && !archivedView && (
                <ItemFormDialog key={adding ? 'open' : 'closed'} open={adding} defaultSource={filters.source} categories={categories} onClose={() => setAdding(false)} />
            )}
        </AppLayout>
    );
}

function Pager({ data }: { data: Paginated<Item> }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground">
            <p>{data.total === 0 ? 'No entries' : `Showing ${data.from} to ${data.to} of ${data.total} entries`}</p>
            {data.links.length > 3 && (
                <div className="flex flex-wrap gap-1">
                    {data.links.map((l, i) => {
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
