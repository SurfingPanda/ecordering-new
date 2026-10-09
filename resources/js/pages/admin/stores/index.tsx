import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CloudDownload, Plus, Power, Search } from 'lucide-react';
import Swal from 'sweetalert2';

import { useConfirm } from '@/components/confirm-dialog';
import StoreFormDialog, { type StoreRow } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Props {
    stores: Paginated<StoreRow>;
    filters: { search: string; status: 'active' | 'inactive' | null };
    ecpos: { configured: boolean; last_sync: string | null };
}

export default function Stores({ stores, filters, ecpos }: Props) {
    const [query, setQuery] = useState(filters.search);
    const [dialog, setDialog] = useState<{ store: StoreRow | null } | null>(null);

    const visit = (next: Partial<Props['filters']>) => {
        const f = { ...filters, ...next };
        router.get('/admin/stores', { search: f.search || undefined, status: f.status ?? undefined }, { preserveState: true, replace: true });
    };

    useEffect(() => {
        if (query === filters.search) return;
        const t = setTimeout(() => visit({ search: query }), 300);
        return () => clearTimeout(t);
    }, [query]);

    const [confirm, confirmDialog] = useConfirm();

    // ---- pull the store list from ECPOS: link the stores we have, add the rest ----
    const [syncing, setSyncing] = useState(false);
    const runSync = async () => {
        setSyncing(true);
        const common = { confirmButtonText: 'OK', showCloseButton: true, allowOutsideClick: false, customClass: { confirmButton: 'swal-confirm' } };
        void Swal.fire({
            title: 'Syncing stores from ECPOS…',
            html: '<p style="margin:0 0 .9rem;color:#6b7280;font-size:.95rem">Fetching the store list. Please keep this page open.</p><div class="sync-progress"><span></span></div>',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading(),
        });
        const started = Date.now();
        try {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const res = await fetch('/admin/stores/ecpos-sync', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } });
            const body = await res.json().catch(() => ({}));
            await new Promise((r) => setTimeout(r, Math.max(0, 900 - (Date.now() - started))));
            if (!res.ok) {
                void Swal.fire({ ...common, icon: 'error', title: 'Sync failed', text: body.message ?? 'Something went wrong. Nothing was changed.' });
                return;
            }
            await new Promise<void>((done) => router.reload({ only: ['stores', 'ecpos'], onFinish: () => done() }));
            const rows = [
                ['New stores added', body.added],
                ['Existing stores linked to ECPOS', body.linked],
                ['Already linked', body.unchanged],
                ['Skipped (test stores or name clashes)', body.skipped],
            ] as const;
            const changed = body.added + body.linked > 0;
            void Swal.fire({
                ...common,
                icon: changed ? 'success' : 'info',
                title: changed ? 'Stores synced' : 'Already up to date',
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
            title: 'Sync stores from ECPOS?',
            description: 'Stores you already have are only linked to their ECPOS store ID (nothing else about them changes). ECPOS stores you don’t have yet are added without a login. ECPOS test stores are skipped.',
            confirmLabel: 'Sync now',
            onConfirm: runSync,
        });
    const toggle = (s: StoreRow) =>
        confirm(
            s.is_active
                ? {
                      title: `Deactivate ${s.name}?`,
                      description: 'The store will no longer be able to place or post orders. Its orders and history are kept, and you can activate it again at any time.',
                      confirmLabel: 'Deactivate store',
                      tone: 'danger',
                      onConfirm: () => router.patch(`/admin/stores/${s.id}/status`, {}, { preserveScroll: true }),
                  }
                : {
                      title: `Activate ${s.name}?`,
                      description: 'The store will be able to place and post orders again.',
                      confirmLabel: 'Activate store',
                      onConfirm: () => router.patch(`/admin/stores/${s.id}/status`, {}, { preserveScroll: true }),
                  },
        );

    return (
        <AppLayout
            title="Stores"
            actions={
                <>
                    {ecpos.configured && (
                        <Button size="sm" variant="ghost" className="border border-border bg-white" onClick={syncFromEcpos} disabled={syncing}>
                            <CloudDownload className={cn('text-secondary', syncing && 'animate-pulse')} /> {syncing ? 'Syncing…' : 'Sync from ECPOS'}
                        </Button>
                    )}
                    <Button size="sm" variant="secondary" onClick={() => setDialog({ store: null })}>
                        <Plus /> Add store
                    </Button>
                </>
            }
        >
            <div className={panel}>
                <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <select
                        aria-label="Filter by status"
                        value={filters.status ?? ''}
                        onChange={(e) => visit({ status: (e.target.value || null) as Props['filters']['status'] })}
                        className={selectCls}
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    <div className="relative ml-auto w-full sm:w-64">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search name or code" aria-label="Search stores" className={cn(field, 'pl-8')} />
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[760px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>Code</th>
                                <th className={th}>Store</th>
                                <th className={th}>Contact</th>
                                <th className={cn(th, 'text-right')}>Logins</th>
                                <th className={cn(th, 'text-right')}>Active TRs</th>
                                <th className={th}>Status</th>
                                <th className={th} />
                            </tr>
                        </thead>
                        <tbody>
                            {stores.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                        {filters.search || filters.status ? 'No stores match.' : 'No stores yet. Add the first one.'}
                                    </td>
                                </tr>
                            )}
                            {stores.data.map((s) => (
                                <tr key={s.id} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc] hover:bg-accent/60">
                                    <td className={cn(td, 'font-mono font-medium')}>
                                        {s.code}
                                        {s.ecpos_store_id ? <p className="text-[11px] font-normal text-muted-foreground">ECPOS {s.ecpos_store_id}</p> : <p className="text-[11px] font-normal text-[#9a5b00]">Not linked</p>}
                                    </td>
                                    <td className={td}>
                                        <Link href={`/admin/stores/${s.id}`} className="font-medium text-secondary hover:underline">
                                            {s.name}
                                        </Link>
                                        {s.address && <p className="text-xs text-muted-foreground">{s.address}</p>}
                                    </td>
                                    <td className={td}>
                                        {s.contact_person ?? '—'}
                                        {s.contact_number && <p className="text-xs text-muted-foreground">{s.contact_number}</p>}
                                    </td>
                                    <td className={cn(td, 'text-right tabular-nums')}>{s.users_count}</td>
                                    <td className={cn(td, 'text-right tabular-nums')}>{s.active_orders_count}</td>
                                    <td className={td}>
                                        <span className={cn('inline-block rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide', s.is_active ? 'bg-[#e3f5ea] text-[#16683a]' : 'bg-[#f1f1f4] text-[#5d6280]')}>
                                            {s.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className={cn(td, 'whitespace-nowrap text-right')}>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/admin/stores/${s.id}`}>View</Link>
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => setDialog({ store: s })}>
                                            Edit
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => toggle(s)} className={s.is_active ? 'text-destructive hover:text-destructive' : ''}>
                                            <Power /> {s.is_active ? 'Deactivate' : 'Activate'}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground">
                    <p>{stores.total === 0 ? 'No entries' : `Showing ${stores.from} to ${stores.to} of ${stores.total} stores`}</p>
                    {stores.links.length > 3 && (
                        <div className="flex flex-wrap gap-1">
                            {stores.links.map((l, i) => {
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

            {confirmDialog}
            {/* keyed so the form starts fresh each time it opens */}
            <StoreFormDialog key={dialog ? (dialog.store?.id ?? 'new') : 'closed'} open={dialog !== null} store={dialog?.store ?? null} onClose={() => setDialog(null)} />
        </AppLayout>
    );
}
