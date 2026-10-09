import { useForm, usePage } from '@inertiajs/react';

import { Field } from '@/components/store-form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { field, panel } from '@/lib/ui';
import type { SharedProps } from '@/types';

export default function Account() {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/account/password', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout title="My account">
            <div className="mx-auto max-w-xl space-y-5">
                <section className={panel}>
                    <h2 className="border-b border-border px-4 py-3 text-sm font-semibold">Signed in as</h2>
                    <dl className="grid gap-3 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-muted-foreground">Name</dt>
                            <dd className="mt-0.5 font-medium">{auth.user.name}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">{auth.user.role === 'admin' ? 'Role' : 'Store'}</dt>
                            <dd className="mt-0.5 font-medium">{auth.user.role === 'admin' ? 'Administrator' : (auth.user.store?.name ?? '—')}</dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-muted-foreground">Email (what you sign in with)</dt>
                            <dd className="mt-0.5 font-medium">{auth.user.email}</dd>
                        </div>
                    </dl>
                </section>

                <section className={panel}>
                    <h2 className="border-b border-border px-4 py-3 text-sm font-semibold">Change password</h2>
                    <form onSubmit={submit} noValidate className="space-y-4 p-4">
                        <Field label="Current password" id="current_password" error={form.errors.current_password}>
                            <Input id="current_password" type="password" className={field} value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} autoComplete="current-password" />
                        </Field>
                        <Field label="New password" id="password" error={form.errors.password} hint="At least 8 characters">
                            <Input id="password" type="password" className={field} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" />
                        </Field>
                        <Field label="Confirm new password" id="password_confirmation">
                            <Input id="password_confirmation" type="password" className={field} value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} autoComplete="new-password" />
                        </Field>
                        <div className="flex justify-end border-t border-border pt-4">
                            <Button type="submit" variant="secondary" disabled={form.processing || !form.data.current_password || !form.data.password}>
                                {form.processing ? 'Saving…' : 'Change password'}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
