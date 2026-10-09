import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Clock, KeyRound, Pencil, Plus, Power } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import StoreFormDialog, { Field, type StoreRow } from '@/components/store-form-dialog';
import { StatusBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDay, formatTime } from '@/lib/format';
import { field, panel } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { OrderStatus } from '@/types';

interface Props {
    store: StoreRow;
    users: { id: number; name: string; email: string; created_at: string }[];
    store_deadline: { time: string; source: 'date' | 'store' | 'default' };
    counts: { pending: number; posted: number; cancelled: number };
    recent: { id: number; number: string; status: OrderStatus; order_date: string }[];
}

const sourceLabel = { date: 'set for today only', store: 'this store’s own deadline', default: 'the default deadline' } as const;

export default function ShowStore({ store, users, store_deadline, counts, recent }: Props) {
    const [editing, setEditing] = useState(false);
    const [addingUser, setAddingUser] = useState(false);
    const [resetting, setResetting] = useState<Props['users'][number] | null>(null);

    const [confirm, confirmDialog] = useConfirm();

    const toggle = () =>
        confirm(
            store.is_active
                ? {
                      title: `Deactivate ${store.name}?`,
                      description: 'The store will no longer be able to place or post orders. Its orders and history are kept, and you can activate it again at any time.',
                      confirmLabel: 'Deactivate store',
                      tone: 'danger',
                      onConfirm: () => router.patch(`/admin/stores/${store.id}/status`, {}, { preserveScroll: true }),
                  }
                : {
                      title: `Activate ${store.name}?`,
                      description: 'The store will be able to place and post orders again.',
                      confirmLabel: 'Activate store',
                      onConfirm: () => router.patch(`/admin/stores/${store.id}/status`, {}, { preserveScroll: true }),
                  },
        );

    return (
        <AppLayout
            title={store.name}
            actions={
                <Button asChild variant="ghost" size="sm">
                    <Link href="/admin/stores">
                        <ArrowLeft /> All stores
                    </Link>
                </Button>
            }
        >
            <div className="grid items-start gap-5 lg:grid-cols-[1fr_340px]">
                <div className="space-y-5">
                    <section className={panel}>
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <h2 className="text-sm font-semibold">Store details</h2>
                            <div className="flex gap-1">
                                <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                                    <Pencil /> Edit
                                </Button>
                                <Button variant="ghost" size="sm" onClick={toggle} className={store.is_active ? 'text-destructive hover:text-destructive' : ''}>
                                    <Power /> {store.is_active ? 'Deactivate' : 'Activate'}
                                </Button>
                            </div>
                        </div>
                        <dl className="grid gap-x-6 gap-y-4 p-4 text-sm sm:grid-cols-2">
                            <Detail label="Code" value={store.code} mono />
                            <Detail label="ECPOS store ID" value={store.ecpos_store_id} mono />
                            <Detail label="Status" value={store.is_active ? 'Active' : 'Inactive — cannot submit orders'} />
                            <Detail label="Address" value={store.address} />
                            <Detail label="Contact person" value={store.contact_person} />
                            <Detail label="Contact number" value={store.contact_number} />
                            <Detail label="Email" value={store.email} />
                        </dl>
                    </section>

                    <section className={panel}>
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <h2 className="text-sm font-semibold">Logins</h2>
                            <Button variant="ghost" size="sm" onClick={() => setAddingUser(true)}>
                                <Plus /> Add login
                            </Button>
                        </div>
                        {users.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-muted-foreground">No one can sign in for this store yet. Add a login.</p>
                        ) : (
                            <ul className="divide-y divide-border/70">
                                {users.map((u) => (
                                    <li key={u.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-[13px]">
                                        <span className="font-medium">{u.name}</span>
                                        <span className="ml-auto text-muted-foreground">{u.email}</span>
                                        <Button variant="ghost" size="sm" onClick={() => setResetting(u)}>
                                            <KeyRound /> Reset password
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <div className="space-y-5">
                    <section className={cn(panel, 'p-4')}>
                        <h2 className="mb-3 text-sm font-semibold">Submission deadline</h2>
                        <p className="flex items-center gap-2 text-2xl font-semibold tabular-nums">
                            <Clock className="size-5 text-muted-foreground" />
                            {formatTime(store_deadline.time)}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">Today’s cutoff — {sourceLabel[store_deadline.source]}.</p>
                        <Link href="/admin/settings" className="mt-3 inline-block text-[13px] font-medium text-secondary hover:underline">
                            Change in Order Settings
                        </Link>
                    </section>

                    <section className={panel}>
                        <h2 className="border-b border-border px-4 py-3 text-sm font-semibold">Orders</h2>
                        <div className="grid grid-cols-3 gap-2 p-4 text-center">
                            {(['pending', 'posted', 'cancelled'] as const).map((k) => (
                                <Link key={k} href={`/orders?status=${k}&store=${store.id}`} className="rounded-md bg-[#f2f4f8] px-2 py-2 hover:bg-accent">
                                    <p className="text-xl font-semibold tabular-nums">{counts[k]}</p>
                                    <p className="text-xs capitalize text-muted-foreground">{k}</p>
                                </Link>
                            ))}
                        </div>
                        {recent.length > 0 && (
                            <ul className="divide-y divide-border/70 border-t border-border">
                                {recent.map((o) => (
                                    <li key={o.id}>
                                        <Link href={`/orders/${o.id}`} className="flex items-center gap-3 px-4 py-2 text-[13px] hover:bg-accent/60">
                                            <span className="flex-1 font-mono text-secondary">{o.number}</span>
                                            <span className="text-muted-foreground">{formatDay(o.order_date)}</span>
                                            <StatusBadge status={o.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>

            {confirmDialog}
            <StoreFormDialog key={editing ? 'edit' : 'closed'} open={editing} store={store} onClose={() => setEditing(false)} />
            <ResetPasswordDialog key={resetting?.id ?? 'closed'} user={resetting} storeId={store.id} onClose={() => setResetting(null)} />
            <AddLoginDialog key={addingUser ? 'add' : 'closed'} open={addingUser} storeId={store.id} storeName={store.name} onClose={() => setAddingUser(false)} />
        </AppLayout>
    );
}

function ResetPasswordDialog({ user, storeId, onClose }: { user: Props['users'][number] | null; storeId: number; onClose: () => void }) {
    const form = useForm({ password: '', password_confirmation: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!user) return;
        form.put(`/admin/stores/${storeId}/users/${user.id}/password`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={user !== null} onOpenChange={(o) => !o && !form.processing && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Reset password</DialogTitle>
                <DialogDescription>
                    Set a new password for {user?.name} ({user?.email}). Share it with them directly.
                </DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="New password" id="reset_password" error={form.errors.password} hint="At least 8 characters">
                        <Input id="reset_password" type="password" className={field} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" autoFocus />
                    </Field>
                    <Field label="Confirm new password" id="reset_password_confirmation">
                        <Input id="reset_password_confirmation" type="password" className={field} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" />
                    </Field>
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" disabled={form.processing} onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Reset password'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AddLoginDialog({ open, storeId, storeName, onClose }: { open: boolean; storeId: number; storeName: string; onClose: () => void }) {
    const form = useForm({ name: '', email: '', password: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/admin/stores/${storeId}/users`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Add a login for {storeName}</DialogTitle>
                <DialogDescription>This person will only ever see {storeName}’s orders.</DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="Name" id="login_name" error={form.errors.name}>
                        <Input id="login_name" className={field} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Login email" id="login_email" error={form.errors.email}>
                        <Input id="login_email" type="email" className={field} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="off" />
                    </Field>
                    <Field label="Initial password" id="login_password" error={form.errors.password} hint="At least 8 characters">
                        <Input id="login_password" type="password" className={field} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" />
                    </Field>
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            Add login
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Detail({ label, value, mono }: { label: string; value: string | null; mono?: boolean }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={cn('mt-0.5 font-medium', mono && 'font-mono', !value && 'text-muted-foreground/60')}>{value || '—'}</dd>
        </div>
    );
}
