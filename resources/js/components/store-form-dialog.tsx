import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { field, selectCls } from '@/lib/ui';
import { cn } from '@/lib/utils';

export interface StoreRow {
    id: number;
    code: string;
    ecpos_store_id: string | null;
    name: string;
    address: string | null;
    contact_person: string | null;
    contact_number: string | null;
    email: string | null;
    is_active: boolean;
    users_count: number;
    active_orders_count: number;
}

/** Add a store (optionally with its first login) or edit an existing one. */
export default function StoreFormDialog({ open, store, onClose }: { open: boolean; store: StoreRow | null; onClose: () => void }) {
    const editing = store !== null;
    const form = useForm({
        name: store?.name ?? '',
        code: store?.code ?? '',
        ecpos_store_id: store?.ecpos_store_id ?? '',
        address: store?.address ?? '',
        contact_person: store?.contact_person ?? '',
        contact_number: store?.contact_number ?? '',
        email: store?.email ?? '',
        is_active: store?.is_active ?? true,
        user_name: '',
        user_email: '',
        user_password: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (editing) {
            // the login fields only exist when creating
            form.transform(({ user_name, user_email, user_password, ...rest }) => rest);
            form.put(`/admin/stores/${store.id}`, opts);
        } else {
            form.post('/admin/stores', opts);
        }
    };

    const text = (k: 'name' | 'code' | 'ecpos_store_id' | 'address' | 'contact_person' | 'contact_number' | 'email' | 'user_name' | 'user_email' | 'user_password') => ({
        id: k,
        value: form.data[k],
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => form.setData(k, e.target.value),
        'aria-invalid': !!form.errors[k],
        className: field,
    });

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogTitle>{editing ? `Edit ${store.name}` : 'Add store'}</DialogTitle>
                <DialogDescription>
                    {editing ? 'Orders and history are never changed by editing a store.' : 'Create the store and, optionally, the login its staff will use.'}
                </DialogDescription>

                <form onSubmit={submit} noValidate className="mt-5 grid gap-4 sm:grid-cols-2">
                    <Field label="Store name" id="name" error={form.errors.name}>
                        <Input {...text('name')} autoFocus />
                    </Field>
                    <Field label="Store code" id="code" error={form.errors.code} hint="Unique, e.g. SF01">
                        <Input {...text('code')} className={cn(field, 'uppercase')} />
                    </Field>
                    <Field label="ECPOS store ID" id="ecpos_store_id" error={form.errors.ecpos_store_id} hint="Optional, e.g. BW0018. Sent with orders to ECPOS." className="sm:col-span-2">
                        <Input {...text('ecpos_store_id')} className={cn(field, 'uppercase')} />
                    </Field>
                    <Field label="Address" id="address" error={form.errors.address} className="sm:col-span-2">
                        <Input {...text('address')} />
                    </Field>
                    <Field label="Contact person" id="contact_person" error={form.errors.contact_person}>
                        <Input {...text('contact_person')} />
                    </Field>
                    <Field label="Contact number" id="contact_number" error={form.errors.contact_number}>
                        <Input {...text('contact_number')} inputMode="tel" />
                    </Field>
                    <Field label="Email address" id="email" error={form.errors.email}>
                        <Input {...text('email')} type="email" />
                    </Field>
                    <Field label="Status" id="is_active" error={form.errors.is_active}>
                        <select id="is_active" className={cn(selectCls, 'w-full')} value={form.data.is_active ? '1' : '0'} onChange={(e) => form.setData('is_active', e.target.value === '1')}>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </Field>

                    {!editing && (
                        <fieldset className="grid gap-4 rounded-md border border-border p-3 sm:col-span-2 sm:grid-cols-2">
                            <legend className="px-1 text-xs font-semibold text-muted-foreground">Store login (optional — you can add it later)</legend>
                            <Field label="User name" id="user_name" error={form.errors.user_name}>
                                <Input {...text('user_name')} autoComplete="off" />
                            </Field>
                            <Field label="Login email" id="user_email" error={form.errors.user_email}>
                                <Input {...text('user_email')} type="email" autoComplete="off" />
                            </Field>
                            <Field label="Initial password" id="user_password" error={form.errors.user_password} hint="At least 8 characters" className="sm:col-span-2">
                                <Input {...text('user_password')} type="password" autoComplete="new-password" />
                            </Field>
                        </fieldset>
                    )}

                    <div className="flex justify-end gap-2 border-t border-border pt-4 sm:col-span-2">
                        <Button type="button" variant="ghost" size="sm" className="border border-border" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" size="sm" disabled={form.processing}>
                            {form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add store'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function Field({
    label,
    id,
    error,
    hint,
    required,
    className,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    hint?: string;
    /** Shows a red asterisk after the label. */
    required?: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={id} className="text-[13px]">
                {label}
                {required && (
                    <span className="text-destructive" aria-hidden="true">
                        *
                    </span>
                )}
            </Label>
            {children}
            {error ? <p className="text-xs font-medium text-destructive">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
