import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { ArrowLeft, Check, Copy, Loader2, Minus, Plus, Search, X } from 'lucide-react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { catalogLabel, catalogs } from '@/lib/charts';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

interface CatalogItem {
    id: number;
    source: ItemSource;
    product_code: string;
    description: string;
    category: string | null;
}

const MAX_QTY = 9999;

const tabs: { key: ItemSource | 'all'; label: string }[] = [{ key: 'all', label: 'All' }, ...catalogs.map((s) => ({ key: s, label: catalogLabel[s] }))];

interface Saved {
    notes: string;
    lines: { item_id: number; quantity: number }[];
}

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

export default function CreateOrder({ order, items, blocked, saved }: { order: { id: number; number: string; order_date: string }; items: CatalogItem[]; blocked: string | null; saved: Saved }) {
    const [tab, setTab] = useState<ItemSource | 'all'>('all');
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('');
    const [selectedOnly, setSelectedOnly] = useState(false);
    // start from whatever was autosaved on this draft, so leaving the page and coming back keeps the order
    const [qty, setQty] = useState<Record<number, number>>(() => Object.fromEntries(saved.lines.map((l) => [l.item_id, l.quantity])));
    const inputs = useRef<Record<number, HTMLInputElement | null>>({});

    const form = useForm({ order_date: order.order_date, notes: saved.notes });

    // ---- autosave: debounce changes to the draft, and flush right away when leaving the page ----
    const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>(saved.lines.length > 0 ? 'saved' : 'idle');
    const latest = useRef({ qty, date: form.data.order_date, notes: form.data.notes });
    const dirty = useRef(false);
    const submitting = useRef(false);
    const first = useRef(true);

    const payload = () =>
        JSON.stringify({
            order_date: latest.current.date,
            notes: latest.current.notes,
            lines: Object.entries(latest.current.qty).map(([id, q]) => ({ item_id: Number(id), quantity: q })),
        });

    const flush = (keepalive = false) => {
        if (!dirty.current || submitting.current) return Promise.resolve();
        dirty.current = false;
        setSaveState('saving');

        return fetch('/orders/draft/lines', {
            method: 'PUT',
            keepalive,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: payload(),
        })
            .then((r) => {
                if (!r.ok) throw new Error(String(r.status));
                setSaveState(dirty.current ? 'saving' : 'saved');
            })
            .catch(() => {
                dirty.current = true;
                setSaveState('error');
            });
    };

    useEffect(() => {
        latest.current = { qty, date: form.data.order_date, notes: form.data.notes };
        if (first.current) {
            first.current = false;
            return;
        }
        dirty.current = true;
        setSaveState('saving');
        const t = setTimeout(() => void flush(), 600);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [qty, form.data.order_date, form.data.notes]);

    useEffect(() => {
        const onHide = () => void flush(true);
        window.addEventListener('pagehide', onHide);
        // leaving through the app's own links unmounts this page: save what's pending first
        return () => {
            window.removeEventListener('pagehide', onHide);
            void flush(true);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ---- copy the store's last order into this sheet ----
    const [copying, setCopying] = useState(false);
    const copyLastOrder = async () => {
        const common = { confirmButtonText: 'OK', showCloseButton: true, allowOutsideClick: false, customClass: { confirmButton: 'swal-confirm' } };
        setCopying(true);
        try {
            const res = await fetch('/orders/last', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(String(res.status));
            const { order: last } = (await res.json()) as { order: null | { number: string; order_date: string; lines: { item_id: number; quantity: number }[]; unavailable: number } };

            if (!last) {
                void Swal.fire({ ...common, icon: 'info', title: 'No previous order', text: 'You haven’t placed an order yet, so there is nothing to copy.' });
                return;
            }

            // only items that are still on this sheet
            const usable = last.lines.filter((l) => byId.has(l.item_id));
            const skipped = last.unavailable + (last.lines.length - usable.length);
            if (usable.length === 0) {
                void Swal.fire({ ...common, icon: 'info', title: 'Nothing to copy', text: `None of the items in ${last.number} are available any more.` });
                return;
            }

            if (selected.length > 0) {
                const ok = await Swal.fire({
                    icon: 'question',
                    title: 'Replace what you’ve entered?',
                    text: `This fills the sheet with the ${usable.length} ${usable.length === 1 ? 'item' : 'items'} from ${last.number}. The quantities you’ve entered now will be replaced.`,
                    showCancelButton: true,
                    confirmButtonText: 'Replace',
                    cancelButtonText: 'Keep mine',
                    allowOutsideClick: false,
                    customClass: { confirmButton: 'swal-confirm' },
                });
                if (!ok.isConfirmed) return;
            }

            setQty(Object.fromEntries(usable.map((l) => [l.item_id, Math.min(MAX_QTY, l.quantity)])));
            setSelectedOnly(false);
            void Swal.fire({
                ...common,
                icon: 'success',
                title: `Copied ${usable.length} ${usable.length === 1 ? 'item' : 'items'}`,
                text: `From ${last.number}. Change any quantity you need, then place the order.${skipped > 0 ? ` ${skipped} ${skipped === 1 ? 'item was' : 'items were'} left out because ${skipped === 1 ? 'it is' : 'they are'} no longer available.` : ''}`,
            });
        } catch {
            void Swal.fire({ ...common, icon: 'error', title: 'Couldn’t copy the last order', text: 'Please check your connection and try again.' });
        } finally {
            setCopying(false);
        }
    };

    const byId = useMemo(() => new Map(items.map((i) => [i.id, i])), [items]);
    const categories = useMemo(
        () => [...new Set(items.filter((i) => tab === 'all' || i.source === tab).map((i) => i.category).filter(Boolean))].sort() as string[],
        [items, tab],
    );

    const selected = items.filter((i) => (qty[i.id] ?? 0) > 0);
    const totalUnits = selected.reduce((sum, i) => sum + qty[i.id], 0);

    const q = query.trim().toLowerCase();
    const visible = items.filter(
        (i) =>
            (tab === 'all' || i.source === tab) &&
            (category === '' || i.category === category) &&
            (!selectedOnly || (qty[i.id] ?? 0) > 0) &&
            (q === '' || `${i.description} ${i.product_code} ${i.category ?? ''}`.toLowerCase().includes(q)),
    );

    const setItemQty = (id: number, value: number) =>
        setQty((prev) => {
            const next = { ...prev };
            const v = Math.max(0, Math.min(MAX_QTY, Math.floor(value) || 0));
            if (v === 0) delete next[id];
            else next[id] = v;
            return next;
        });

    const focusRow = (index: number) => {
        const row = visible[index];
        const el = row && inputs.current[row.id];
        if (el) {
            el.focus();
            el.select();
        }
    };

    const onKey = (e: React.KeyboardEvent<HTMLInputElement>, index: number) => {
        if (e.key === 'Enter' || e.key === 'ArrowDown') {
            e.preventDefault(); // Enter moves to the next row; it never submits the order by accident
            focusRow(index + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            focusRow(index - 1);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: selected.map((i) => ({ item_id: i.id, quantity: qty[i.id] })),
        }));
        submitting.current = true;
        form.post('/orders', { onError: () => (submitting.current = false) });
    };

    const lineError = (form.errors as Record<string, string>).lines ?? Object.entries(form.errors).find(([k]) => k.startsWith('lines.'))?.[1];
    // `blocked` is the server's reason ordering is closed (deadline passed / store deactivated); the server re-checks on submit
    const canSubmit = selected.length > 0 && !form.processing && !blocked;
    const closedMessage = blocked ?? (form.errors as Record<string, string>).deadline;

    return (
        <AppLayout
            title="New order"
            actions={
                <>
                    <p className="hidden text-sm text-muted-foreground sm:block">
                        Order ID <span className="ml-1 font-mono font-semibold tracking-wide text-foreground">{order.number}</span>
                    </p>
                    <SaveStatus state={saveState} />
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/orders">
                            <ArrowLeft /> Back to orders
                        </Link>
                    </Button>
                </>
            }
        >
            <form id="order-form" onSubmit={submit} noValidate className="grid items-start gap-4 pb-20 sm:gap-5 xl:grid-cols-[1fr_340px] xl:pb-0">
                <section className={cn(panel, 'min-w-0')}>
                    <div className="space-y-3 border-b border-border p-3 sm:p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="no-scrollbar -mx-1 flex max-w-full gap-2 overflow-x-auto px-1">
                            {tabs.map((t) => (
                                <button
                                    key={t.key}
                                    type="button"
                                    onClick={() => {
                                        setTab(t.key);
                                        setCategory('');
                                    }}
                                    className={cn(
                                        'shrink-0 rounded-md border px-3 py-2 text-[13px] font-semibold sm:py-1.5',
                                        tab === t.key
                                            ? 'border-secondary bg-secondary text-secondary-foreground'
                                            : 'border-border bg-white text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {t.label}
                                </button>
                            ))}
                            </div>
                            <button
                                type="button"
                                onClick={copyLastOrder}
                                disabled={copying || !!blocked}
                                className="inline-flex h-10 w-full items-center justify-center gap-1.5 rounded-md border border-secondary sm:ml-auto sm:h-9 sm:w-auto bg-white px-3 text-[13px] font-semibold text-secondary transition-colors hover:bg-accent disabled:opacity-50"
                            >
                                {copying ? <Loader2 className="size-4 animate-spin" /> : <Copy className="size-4" />}
                                Copy last order
                            </button>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <div className="relative min-w-52 flex-1">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search by code or description"
                                    aria-label="Search items"
                                    autoFocus
                                    className={cn(field, 'pl-8')}
                                />
                            </div>
                            <select aria-label="Category" value={category} onChange={(e) => setCategory(e.target.value)} className={cn(selectCls, 'min-w-0 flex-1 sm:flex-none')}>
                                <option value="">All categories</option>
                                {categories.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                            <label className="flex shrink-0 cursor-pointer items-center gap-2 text-[13px] font-medium">
                                <input
                                    type="checkbox"
                                    checked={selectedOnly}
                                    onChange={(e) => setSelectedOnly(e.target.checked)}
                                    className="size-4 accent-[var(--secondary)]"
                                />
                                Selected only ({selected.length})
                            </label>
                        </div>
                    </div>

                    <div className="no-scrollbar max-h-[calc(100svh-23rem)] min-h-[260px] overflow-auto md:max-h-[calc(100vh-290px)]">
                        <table className="w-full border-collapse max-md:block md:min-w-[560px]">
                            <thead className="sticky top-0 z-10 bg-secondary text-secondary-foreground max-md:hidden">
                                <tr>
                                    <th className={th}>Product Code</th>
                                    <th className={th}>Description</th>
                                    <th className={th}>Category</th>
                                    <th className={cn(th, 'w-[150px] text-center')}>Quantity</th>
                                </tr>
                            </thead>
                            <tbody className="max-md:block">
                                {visible.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-5 py-14 text-center text-sm text-muted-foreground">
                                            {items.length === 0 ? 'No items yet. Add some under Retails.' : 'No items match.'}
                                        </td>
                                    </tr>
                                )}
                                {visible.map((i, index) => {
                                    const value = qty[i.id] ?? 0;
                                    return (
                                        <tr
                                            key={i.id}
                                            className={cn(
                                                'border-b border-border/70 max-md:grid max-md:grid-cols-[1fr_auto] max-md:items-center max-md:gap-x-2 max-md:px-3 max-md:py-2',
                                                value > 0 ? 'bg-accent shadow-[inset_3px_0_0_var(--secondary)]' : 'odd:bg-white even:bg-[#f8f9fc]',
                                            )}
                                        >
                                            <td className={cn(td, 'whitespace-nowrap max-md:col-start-1 max-md:row-start-2 max-md:whitespace-normal max-md:p-0 max-md:text-xs max-md:text-muted-foreground')}>
                                                {i.product_code}
                                                {i.category && <span className="md:hidden"> · {i.category}</span>}
                                            </td>
                                            <td className={cn(td, value > 0 && 'font-semibold', 'max-md:col-start-1 max-md:row-start-1 max-md:p-0 max-md:text-[14px] max-md:leading-snug')}>{i.description}</td>
                                            <td className={cn(td, 'whitespace-nowrap text-muted-foreground max-md:hidden')}>{i.category}</td>
                                            <td className="px-3 py-1.5 max-md:col-start-2 max-md:row-span-2 max-md:row-start-1 max-md:p-0">
                                                <div className="mx-auto flex w-[144px] items-center gap-1 md:w-[122px]">
                                                    <Stepper label={`Decrease ${i.description}`} disabled={value === 0} onClick={() => setItemQty(i.id, value - 1)}>
                                                        <Minus />
                                                    </Stepper>
                                                    <input
                                                        ref={(el) => {
                                                            inputs.current[i.id] = el;
                                                        }}
                                                        value={value === 0 ? '' : value}
                                                        placeholder="0"
                                                        inputMode="numeric"
                                                        aria-label={`Quantity for ${i.description}`}
                                                        onChange={(e) => setItemQty(i.id, Number(e.target.value.replace(/\D/g, '')))}
                                                        onFocus={(e) => e.target.select()}
                                                        onKeyDown={(e) => onKey(e, index)}
                                                        className={cn(
                                                            'h-10 w-14 rounded border bg-white text-center text-base md:h-8 md:text-sm font-semibold tabular-nums outline-none placeholder:font-normal placeholder:text-muted-foreground/60',
                                                            'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20',
                                                            value > 0 ? 'border-secondary' : 'border-input',
                                                        )}
                                                    />
                                                    <Stepper label={`Increase ${i.description}`} onClick={() => setItemQty(i.id, value + 1)}>
                                                        <Plus />
                                                    </Stepper>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <p className="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                        Tip: type a quantity and press Enter to jump to the next item.
                    </p>
                </section>

                <aside className={cn(panel, 'min-w-0 xl:sticky xl:top-20')}>
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Your order <span className="ml-1 font-mono text-xs font-medium text-muted-foreground">{order.number}</span>
                        </h2>
                        {selected.length > 0 && (
                            <button type="button" onClick={() => setQty({})} className="text-xs font-medium text-muted-foreground hover:text-destructive">
                                Clear all
                            </button>
                        )}
                    </div>

                    <div className="p-4">
                        <div className="mb-3 grid grid-cols-2 gap-3 text-center">
                            <Stat label="Items" value={selected.length} />
                            <Stat label="Total units" value={totalUnits} />
                        </div>

                        {selected.length === 0 ? (
                            <p className="py-5 text-center text-sm text-muted-foreground">Enter a quantity beside any item to add it.</p>
                        ) : (
                            <ul className="no-scrollbar max-h-64 divide-y divide-border/70 overflow-y-auto rounded-md border border-border/70">
                                {selected.map((i) => (
                                    <li key={i.id} className="flex items-center gap-2 px-3 py-2">
                                        <span className="min-w-0 flex-1 truncate text-[13px]">{byId.get(i.id)!.description}</span>
                                        <span className="text-[13px] font-semibold tabular-nums">× {qty[i.id]}</span>
                                        <button
                                            type="button"
                                            onClick={() => setItemQty(i.id, 0)}
                                            aria-label={`Remove ${i.description}`}
                                            className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="mt-4 space-y-1.5">
                            <Label htmlFor="order_date" className="text-[13px]">
                                Order date
                            </Label>
                            <Input
                                id="order_date"
                                type="date"
                                value={form.data.order_date}
                                readOnly
                                disabled
                                aria-describedby="order_date_hint"
                                className={cn(field, 'cursor-not-allowed bg-muted text-muted-foreground opacity-100')}
                            />
                            <p id="order_date_hint" className="text-xs text-muted-foreground">
                                Set when you started this order. To change it, ask an admin to cancel the order so you can order again.
                            </p>
                            {form.errors.order_date && <p className="text-xs font-medium text-destructive">{form.errors.order_date}</p>}
                        </div>

                        <div className="mt-4 space-y-1.5">
                            <Label htmlFor="notes" className="text-[13px]">
                                Notes (optional)
                            </Label>
                            <textarea
                                id="notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="e.g. needed by tomorrow morning"
                                className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none placeholder:text-muted-foreground/70 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20"
                            />
                        </div>

                        {closedMessage && <Alert className="mt-4">{closedMessage}</Alert>}
                        {lineError && <Alert className="mt-4">{lineError}</Alert>}

                        <Button type="submit" variant="secondary" className="mt-4 hidden w-full xl:inline-flex" disabled={!canSubmit}>
                            {form.processing ? 'Placing order…' : 'Place order'}
                        </Button>
                    </div>
                </aside>

                {/* small screens: summary + submit stay reachable while scrolling the list */}
                <div className="fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t border-border bg-white px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] xl:hidden">
                    <p className="flex-1 text-sm">
                        <span className="font-semibold">{selected.length}</span> items · <span className="font-semibold">{totalUnits}</span> units
                    </p>
                    <Button type="submit" variant="secondary" size="sm" disabled={!canSubmit}>
                        {form.processing ? 'Placing…' : 'Place order'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

function SaveStatus({ state }: { state: 'idle' | 'saving' | 'saved' | 'error' }) {
    if (state === 'idle') return null;

    return (
        <p role="status" className={cn('hidden items-center gap-1.5 text-xs font-medium sm:flex', state === 'error' ? 'text-destructive' : 'text-muted-foreground')}>
            {state === 'saving' && (
                <>
                    <Loader2 className="size-3.5 animate-spin" /> Saving…
                </>
            )}
            {state === 'saved' && (
                <>
                    <Check className="size-3.5 text-[#16683a]" /> Draft saved
                </>
            )}
            {state === 'error' && 'Couldn’t save — check your connection'}
        </p>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md bg-[#f2f4f8] px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function Stepper({ label, onClick, disabled, children }: { label: string; onClick: () => void; disabled?: boolean; children: React.ReactNode }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            disabled={disabled}
            tabIndex={-1}
            className="grid size-10 shrink-0 place-items-center rounded border border-input bg-white text-foreground md:size-8 hover:border-secondary hover:text-secondary disabled:opacity-35 disabled:hover:border-input disabled:hover:text-foreground [&_svg]:size-3.5"
        >
            {children}
        </button>
    );
}
