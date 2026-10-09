import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ArrowRight, CalendarClock, Globe, History, Plus, RotateCcw } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDay, formatTime } from '@/lib/format';
import { field, panel, selectCls, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';

interface StoreRow {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    effective_time: string;
    source: 'date' | 'store' | 'default';
    recurring: { id: number; time: string } | null;
}

interface Props {
    timezone: string;
    today: string;
    default: { time: string; updated_at: string | null; updated_by: string | null };
    one_order_per_day: boolean;
    stores: StoreRow[];
    overrides: { id: number; date: string; time: string; store: { id: number; code: string; name: string } }[];
    history: { id: number; scope: string; applies_on: string | null; previous: string | null; new: string | null; by: string | null; at: string }[];
}

const sourceBadge = {
    default: { label: 'Default', cls: 'bg-[#f1f1f4] text-[#5d6280]' },
    store: { label: 'Store deadline', cls: 'bg-[#e8ecfb] text-[#1f3aa8]' },
    date: { label: 'Today only', cls: 'bg-[#fff1d6] text-[#8a5200]' },
} as const;

export default function Settings({ timezone, today, default: def, one_order_per_day, stores, overrides, history }: Props) {
    const [dialog, setDialog] = useState<{ storeId: number | null } | null>(null);
    const defaultForm = useForm({ time: def.time });
    const defaultChanged = defaultForm.data.time !== def.time;

    const saveDefault = (e: React.FormEvent) => {
        e.preventDefault();
        defaultForm.put('/admin/settings/default', { preserveScroll: true });
    };

    const [confirm, confirmDialog] = useConfirm();

    const removeRecurring = (s: StoreRow) => {
        const recurring = s.recurring;
        if (!recurring) return;
        confirm({
            title: `Reset ${s.name} to the default?`,
            description: `${s.name} will go back to the default deadline of ${formatTime(def.time)}. Orders already posted are not affected.`,
            confirmLabel: 'Reset to default',
            onConfirm: () => router.delete(`/admin/settings/store-deadlines/${recurring.id}`, { preserveScroll: true }),
        });
    };

    const removeOverride = (o: Props['overrides'][number]) =>
        confirm({
            title: 'Remove this deadline?',
            description: `The ${formatTime(o.time)} deadline for ${o.store.name} on ${formatDay(o.date)} will be removed, so that day uses the store’s usual deadline.`,
            confirmLabel: 'Remove deadline',
            tone: 'danger',
            onConfirm: () => router.delete(`/admin/settings/store-deadlines/${o.id}`, { preserveScroll: true }),
        });

    return (
        <AppLayout
            title="Order Settings"
            actions={
                <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-[#f8f9fc] px-2.5 py-1.5 text-xs font-medium text-muted-foreground" title="All cutoff times use this timezone">
                    <Globe className="size-3.5" /> {timezone}
                </span>
            }
        >
            {/* 1 — the two global settings */}
            <div className="mb-5 grid gap-5 lg:grid-cols-[1.5fr_1fr]">
                <section className={cn(panel, 'overflow-hidden')}>
                    <div className="grid sm:grid-cols-[auto_1fr]">
                        <div className="bg-gradient-to-br from-[#f1f4fe] to-[#dbe4fc] px-6 py-5 sm:min-w-[220px]">
                            <p className="text-[13px] font-medium text-foreground/70">Default daily cutoff</p>
                            <p className="mt-1 text-4xl font-semibold leading-none tabular-nums">{formatTime(def.time)}</p>
                            <p className="mt-2 text-xs text-foreground/70">Applies to every store unless it has its own.</p>
                        </div>

                        <form onSubmit={saveDefault} className="flex flex-col justify-center gap-2 p-5">
                            <label htmlFor="default_time" className="text-[13px] font-semibold">
                                Change the default
                            </label>
                            <div className="flex gap-2">
                                <Input
                                    id="default_time"
                                    type="time"
                                    required
                                    className={cn(field, 'max-w-[170px]')}
                                    value={defaultForm.data.time}
                                    onChange={(e) => defaultForm.setData('time', e.target.value)}
                                    aria-invalid={!!defaultForm.errors.time}
                                />
                                <Button type="submit" variant="secondary" size="sm" className="h-9" disabled={defaultForm.processing || !defaultChanged}>
                                    Save
                                </Button>
                            </div>
                            {defaultForm.errors.time && <p className="text-xs font-medium text-destructive">{defaultForm.errors.time}</p>}
                            <p className="text-xs text-muted-foreground">
                                {def.updated_by && def.updated_at ? `Last changed by ${def.updated_by} on ${formatDate(def.updated_at)}.` : 'Never changed — this is the original setting.'}
                            </p>
                        </form>
                    </div>
                </section>

                <section className={cn(panel, 'p-5')}>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="rule-title" className="text-sm font-semibold">
                                One order per store per day
                            </h2>
                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                A store can have only one pending or posted TR for an order date. Cancelling its TR lets it order again.
                            </p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={one_order_per_day}
                            aria-labelledby="rule-title"
                            onClick={() => router.put('/admin/settings/order-rule', { one_order_per_day: !one_order_per_day }, { preserveScroll: true })}
                            className={cn('relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/30', one_order_per_day ? 'bg-secondary' : 'bg-[#c9cde0]')}
                        >
                            <span className={cn('inline-block size-5 rounded-full bg-white shadow transition-transform', one_order_per_day ? 'translate-x-[22px]' : 'translate-x-0.5')} />
                        </button>
                    </div>
                    <p className={cn('mt-4 rounded-md px-3 py-2 text-xs font-medium', one_order_per_day ? 'bg-[#e8ecfb] text-[#1f3aa8]' : 'bg-[#f1f1f4] text-muted-foreground')}>
                        {one_order_per_day ? 'On — one active order per store per date.' : 'Off — stores may place several orders for the same date.'}
                    </p>
                </section>
            </div>

            {/* 2 — what actually applies to each store today */}
            <section className={cn(panel, 'mb-5 overflow-hidden')}>
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div>
                        <h2 className="text-base font-semibold">Store deadlines</h2>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                            Today’s cutoff for each store ({formatDay(today)}). Orders already posted are never affected by a change.
                        </p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => setDialog({ storeId: null })}>
                        <Plus /> Set deadline
                    </Button>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[620px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>Store</th>
                                <th className={th}>Cutoff today</th>
                                <th className={th}>Store’s own deadline</th>
                                <th className={th} />
                            </tr>
                        </thead>
                        <tbody>
                            {stores.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="px-5 py-12 text-center text-sm text-muted-foreground">
                                        No stores yet. Add one under Stores.
                                    </td>
                                </tr>
                            )}
                            {stores.map((s) => (
                                <tr key={s.id} className="border-b border-border/70 odd:bg-white even:bg-[#f8f9fc]">
                                    <td className={cn(td, 'py-3')}>
                                        <p className="text-sm font-semibold">
                                            {s.name}
                                            {!s.is_active && <span className="ml-2 rounded bg-[#f1f1f4] px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">Inactive</span>}
                                        </p>
                                        <p className="font-mono text-xs text-muted-foreground">{s.code}</p>
                                    </td>
                                    <td className={cn(td, 'py-3')}>
                                        <span className="mr-2 text-base font-semibold tabular-nums">{formatTime(s.effective_time)}</span>
                                        <span className={cn('rounded px-1.5 py-0.5 text-[11px] font-semibold', sourceBadge[s.source].cls)}>{sourceBadge[s.source].label}</span>
                                    </td>
                                    <td className={cn(td, 'py-3 text-sm')}>
                                        {s.recurring ? (
                                            <span className="font-medium tabular-nums">{formatTime(s.recurring.time)}</span>
                                        ) : (
                                            <span className="text-muted-foreground/70">Uses the default</span>
                                        )}
                                    </td>
                                    <td className={cn(td, 'whitespace-nowrap py-3 text-right')}>
                                        <Button variant="ghost" size="sm" className="border border-border bg-white" onClick={() => setDialog({ storeId: s.id })}>
                                            Set deadline
                                        </Button>
                                        {s.recurring && (
                                            <Button variant="ghost" size="sm" className="ml-1" onClick={() => removeRecurring(s)}>
                                                <RotateCcw /> Reset
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* 3 — supporting detail */}
            <div className="grid items-start gap-5 lg:grid-cols-2">
                <section className={panel}>
                    <h2 className="flex items-center gap-2 border-b border-border px-5 py-3.5 text-sm font-semibold">
                        <CalendarClock className="size-4 text-muted-foreground" /> Upcoming one-day deadlines
                    </h2>
                    {overrides.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            None set. Choose “Set deadline” and pick <em>Only on one date</em> to give a store extra time on a specific day.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {overrides.map((o) => (
                                <li key={o.id} className="flex items-center gap-3 px-5 py-3 text-[13px]">
                                    <div className="grid size-11 shrink-0 place-items-center rounded-md bg-[#fff1d6] text-center leading-tight text-[#8a5200]">
                                        <span>
                                            <span className="block text-[10px] font-semibold uppercase">{new Date(o.date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short' })}</span>
                                            <span className="block text-base font-semibold">{new Date(o.date + 'T00:00:00').getDate()}</span>
                                        </span>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-semibold">{o.store.name}</p>
                                        <p className="text-xs text-muted-foreground">{formatDay(o.date)}</p>
                                    </div>
                                    <span className="text-sm font-semibold tabular-nums">{formatTime(o.time)}</span>
                                    <Button variant="ghost" size="sm" onClick={() => removeOverride(o)} aria-label={`Remove deadline for ${o.store.name} on ${formatDay(o.date)}`}>
                                        Remove
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className={panel}>
                    <h2 className="flex items-center gap-2 border-b border-border px-5 py-3.5 text-sm font-semibold">
                        <History className="size-4 text-muted-foreground" /> Recent changes
                    </h2>
                    {history.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">No changes yet.</p>
                    ) : (
                        <ul className="divide-y divide-border/70">
                            {history.map((h) => (
                                <li key={h.id} className="px-5 py-3 text-[13px]">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <p className="min-w-0 truncate font-semibold">
                                            {h.scope}
                                            {h.applies_on && <span className="ml-1.5 font-normal text-muted-foreground">· only on {formatDay(h.applies_on)}</span>}
                                        </p>
                                        <time className="shrink-0 text-xs text-muted-foreground">{formatDate(h.at)}</time>
                                    </div>
                                    <p className="mt-1 flex flex-wrap items-center gap-1.5 tabular-nums">
                                        <span className="rounded bg-[#f1f1f4] px-1.5 py-0.5 text-xs">{h.previous ? formatTime(h.previous) : 'default'}</span>
                                        <ArrowRight className="size-3 text-muted-foreground" />
                                        <span className="rounded bg-[#e8ecfb] px-1.5 py-0.5 text-xs font-semibold text-[#1f3aa8]">{h.new ? formatTime(h.new) : 'removed (default)'}</span>
                                        <span className="ml-1 text-xs text-muted-foreground">by {h.by ?? '—'}</span>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {confirmDialog}
            <DeadlineDialog key={dialog ? `d-${dialog.storeId ?? 'new'}` : 'closed'} open={dialog !== null} storeId={dialog?.storeId ?? null} stores={stores} today={today} defaultTime={def.time} onClose={() => setDialog(null)} />
        </AppLayout>
    );
}

function DeadlineDialog({ open, storeId, stores, today, defaultTime, onClose }: { open: boolean; storeId: number | null; stores: StoreRow[]; today: string; defaultTime: string; onClose: () => void }) {
    const current = stores.find((s) => s.id === storeId);
    const [scope, setScope] = useState<'every' | 'date'>('every');
    const form = useForm({ store_id: storeId ? String(storeId) : '', date: today, time: current?.recurring?.time ?? defaultTime });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // a recurring deadline is saved without a date
        form.transform((d) => ({ ...d, date: scope === 'date' ? d.date : null }));
        form.post('/admin/settings/store-deadlines', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Set a store deadline</DialogTitle>
                <DialogDescription>Give a store more (or less) time than the default.</DialogDescription>

                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="Store" id="deadline_store" error={form.errors.store_id}>
                        <select id="deadline_store" className={cn(selectCls, 'w-full')} value={form.data.store_id} onChange={(e) => form.setData('store_id', e.target.value)}>
                            <option value="">Choose a store…</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.code} — {s.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <fieldset className="space-y-2">
                        <legend className="mb-1 text-[13px] font-semibold">Applies</legend>
                        {[
                            ['every', 'Every day (until reset)'],
                            ['date', 'Only on one date'],
                        ].map(([value, label]) => (
                            <label key={value} className="flex cursor-pointer items-center gap-2 text-sm">
                                <input type="radio" name="scope" checked={scope === value} onChange={() => setScope(value as 'every' | 'date')} className="size-4 accent-[var(--secondary)]" />
                                {label}
                            </label>
                        ))}
                    </fieldset>

                    {scope === 'date' && (
                        <Field label="Date" id="deadline_date" error={form.errors.date}>
                            <Input id="deadline_date" type="date" min={today} className={field} value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                        </Field>
                    )}

                    <Field label="Cut-off time" id="deadline_time" error={form.errors.time}>
                        <Input id="deadline_time" type="time" className={field} value={form.data.time} onChange={(e) => form.setData('time', e.target.value)} />
                    </Field>

                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            Save deadline
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
