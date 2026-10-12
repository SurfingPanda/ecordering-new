import { useMemo, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Search, Trash2 } from 'lucide-react';

import { useConfirm } from '@/components/confirm-dialog';
import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { field, panel, td, th } from '@/lib/ui';
import { cn } from '@/lib/utils';

interface StoreRef {
    id: number;
    code: string;
    name: string;
}

interface Manager {
    id: number;
    name: string;
    email: string;
    stores: StoreRef[];
}

interface Props {
    managers: Manager[];
    stores: (StoreRef & { is_active: boolean })[];
}

type Editing = { kind: 'new' } | { kind: 'edit'; manager: Manager } | { kind: 'password'; manager: Manager } | null;

/** "Area Managers": accounts that see (and can act on) the orders and reports of the stores an admin gives them. */
export default function AreaManagers({ managers, stores }: Props) {
    const [editing, setEditing] = useState<Editing>(null);
    const [confirm, confirmDialog] = useConfirm();

    const remove = (m: Manager) =>
        confirm({
            title: `Remove ${m.name}?`,
            description: 'Their login stops working right away and they are signed out.',
            note: <>The stores and their orders are not affected. Orders they cancelled keep their history.</>,
            tone: 'danger',
            confirmLabel: 'Remove account',
            onConfirm: () => router.delete(`/admin/area-managers/${m.id}`, { preserveScroll: true }),
        });

    return (
        <AppLayout
            title="Area Managers"
            actions={
                <Button variant="secondary" size="sm" onClick={() => setEditing({ kind: 'new' })}>
                    <Plus /> Add area manager
                </Button>
            }
        >
            <p className="mb-4 max-w-3xl text-[13px] leading-relaxed text-muted-foreground">
                An area manager signs in and sees the orders, today&rsquo;s ordering and the reports of <strong className="text-foreground">only the stores you give them</strong>. They can cancel a posted TR and send it to ECPOS
                again for those stores, and they get the order emails for them. They can&rsquo;t change the catalog, settings or stores.
            </p>

            <section className={cn(panel, 'overflow-hidden')}>
                <div className="no-scrollbar overflow-x-auto">
                    <table className="w-full min-w-[640px] border-collapse">
                        <thead className="bg-secondary text-secondary-foreground">
                            <tr>
                                <th className={th}>Area manager</th>
                                <th className={th}>Stores they handle</th>
                                <th className={cn(th, 'w-36')} />
                            </tr>
                        </thead>
                        <tbody>
                            {managers.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="px-5 py-12 text-center text-sm text-muted-foreground">
                                        No area managers yet. Add the first one with the button above.
                                    </td>
                                </tr>
                            )}
                            {managers.map((m) => (
                                <tr key={m.id} className="border-b border-border/70 align-top odd:bg-white even:bg-[#f8f9fc]">
                                    <td className={td}>
                                        <p className="font-semibold">{m.name}</p>
                                        <p className="text-xs text-muted-foreground">{m.email}</p>
                                    </td>
                                    <td className={td}>
                                        <p className="text-xs font-semibold text-secondary">
                                            {m.stores.length} {m.stores.length === 1 ? 'store' : 'stores'}
                                        </p>
                                        <p className="mt-1 flex flex-wrap gap-1.5">
                                            {m.stores.slice(0, 6).map((s) => (
                                                <span key={s.id} className="rounded bg-accent px-1.5 py-0.5 text-xs font-medium text-accent-foreground">
                                                    {s.name}
                                                </span>
                                            ))}
                                            {m.stores.length > 6 && <span className="px-1 py-0.5 text-xs text-muted-foreground">and {m.stores.length - 6} more</span>}
                                        </p>
                                    </td>
                                    <td className={cn(td, 'whitespace-nowrap text-right')}>
                                        <Button variant="ghost" size="sm" onClick={() => setEditing({ kind: 'edit', manager: m })} title="Edit name, email and stores" aria-label={`Edit ${m.name}`}>
                                            <Pencil />
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => setEditing({ kind: 'password', manager: m })} title="Reset password" aria-label={`Reset password for ${m.name}`}>
                                            <KeyRound />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => remove(m)}
                                            title="Remove account"
                                            aria-label={`Remove ${m.name}`}
                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {confirmDialog}
            <ManagerDialog key={editing?.kind === 'edit' ? `e${editing.manager.id}` : editing?.kind === 'new' ? 'new' : 'x'} editing={editing?.kind === 'new' || editing?.kind === 'edit' ? editing : null} stores={stores} onClose={() => setEditing(null)} />
            <PasswordDialog key={editing?.kind === 'password' ? `p${editing.manager.id}` : 'x'} manager={editing?.kind === 'password' ? editing.manager : null} onClose={() => setEditing(null)} />
        </AppLayout>
    );
}

/** Pick stores from a searchable list. */
function StorePicker({ stores, value, onChange, error }: { stores: Props['stores']; value: number[]; onChange: (ids: number[]) => void; error?: string }) {
    const [query, setQuery] = useState('');
    const q = query.trim().toLowerCase();
    const shown = useMemo(() => stores.filter((s) => q === '' || `${s.name} ${s.code}`.toLowerCase().includes(q)), [stores, q]);
    const chosen = new Set(value);

    const toggle = (id: number) => onChange(chosen.has(id) ? value.filter((v) => v !== id) : [...value, id]);
    const allShown = shown.length > 0 && shown.every((s) => chosen.has(s.id));

    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <span className="text-[13px] font-medium">
                    Stores they handle <span className="text-muted-foreground">({value.length} picked)</span>
                </span>
                <span className="flex gap-3 text-xs font-semibold text-secondary">
                    <button type="button" className="hover:underline" onClick={() => onChange([...new Set([...value, ...shown.map((s) => s.id)])])} disabled={allShown}>
                        Select shown
                    </button>
                    <button type="button" className="hover:underline" onClick={() => onChange(value.filter((v) => !shown.some((s) => s.id === v)))} disabled={!shown.some((s) => chosen.has(s.id))}>
                        Clear shown
                    </button>
                </span>
            </div>
            <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input className={cn(field, 'pl-8')} placeholder="Search stores" aria-label="Search stores" value={query} onChange={(e) => setQuery(e.target.value)} />
            </div>
            <ul className="no-scrollbar relative mt-2 max-h-[clamp(7rem,30vh,14rem)] divide-y divide-border/70 overflow-y-auto rounded-md border border-border">
                {shown.length === 0 && <li className="px-3 py-6 text-center text-sm text-muted-foreground">No store matches.</li>}
                {shown.map((s) => (
                    <li key={s.id}>
                        <label className={cn('flex cursor-pointer items-center gap-3 px-3 py-2 text-[13px] hover:bg-accent/60', chosen.has(s.id) && 'bg-accent')}>
                            <Checkbox checked={chosen.has(s.id)} onCheckedChange={() => toggle(s.id)} />
                            <span className="min-w-0 flex-1 truncate font-medium">{s.name}</span>
                            <span className="font-mono text-xs text-muted-foreground">{s.code}</span>
                            {!s.is_active && <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">inactive</span>}
                        </label>
                    </li>
                ))}
            </ul>
            {error && <p className="mt-1.5 text-xs font-medium text-destructive">{error}</p>}
        </div>
    );
}

function ManagerDialog({ editing, stores, onClose }: { editing: { kind: 'new' } | { kind: 'edit'; manager: Manager } | null; stores: Props['stores']; onClose: () => void }) {
    const manager = editing?.kind === 'edit' ? editing.manager : null;
    const form = useForm<{ name: string; email: string; password: string; stores: number[] }>({
        name: manager?.name ?? '',
        email: manager?.email ?? '',
        password: '',
        stores: manager?.stores.map((s) => s.id) ?? [],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (manager) {
            form.transform(({ password: _unused, ...rest }) => rest);
            form.put(`/admin/area-managers/${manager.id}`, opts);
        }
        else form.post('/admin/area-managers', opts);
    };

    const storesError = form.errors.stores ?? (form.errors as Record<string, string>)['stores.0'];

    return (
        <Dialog open={editing !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="no-scrollbar max-w-lg">
                <DialogTitle>{manager ? `Edit ${manager.name}` : 'Add an area manager'}</DialogTitle>
                <DialogDescription>{manager ? 'Change who they are or which stores they handle.' : 'They sign in with this email and password, and see only the stores you pick.'}</DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" id="am-name" error={form.errors.name}>
                            <Input id="am-name" className={field} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus aria-invalid={!!form.errors.name} />
                        </Field>
                        <Field label="Email (their login)" id="am-email" error={form.errors.email}>
                            <Input id="am-email" type="email" className={field} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                        </Field>
                    </div>
                    {!manager && (
                        <Field label="Password" id="am-password" error={form.errors.password}>
                            <Input id="am-password" type="text" autoComplete="off" className={field} placeholder="At least 8 characters" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} aria-invalid={!!form.errors.password} />
                        </Field>
                    )}
                    <StorePicker stores={stores} value={form.data.stores} onChange={(ids) => form.setData('stores', ids)} error={storesError} />
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            {manager ? 'Save changes' : 'Add area manager'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PasswordDialog({ manager, onClose }: { manager: Manager | null; onClose: () => void }) {
    const form = useForm({ password: '', password_confirmation: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!manager) return;
        form.put(`/admin/area-managers/${manager.id}/password`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={manager !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>Reset password</DialogTitle>
                <DialogDescription>{manager ? `Choose a new password for ${manager.name}, then share it with them. They are signed out everywhere.` : ''}</DialogDescription>
                <form onSubmit={submit} noValidate className="mt-5 space-y-4">
                    <Field label="New password" id="pw" error={form.errors.password}>
                        <Input id="pw" type="text" autoComplete="off" className={field} placeholder="At least 8 characters" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoFocus />
                    </Field>
                    <Field label="Type it again" id="pw2">
                        <Input id="pw2" type="text" autoComplete="off" className={field} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2 border-t border-border pt-4">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing || form.data.password.length < 8}>
                            Reset password
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
