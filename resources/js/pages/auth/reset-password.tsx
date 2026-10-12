import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Eye, EyeOff, Lock } from 'lucide-react';

import AuthShell from '@/components/auth-shell';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const [show, setShow] = useState(false);
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const linkError = form.errors.email ?? (form.errors as Record<string, string>).token;

    return (
        <>
            <Head title="Choose a new password" />
            <AuthShell onSubmit={submit}>
                <h1 className="text-center font-display text-[2rem] font-extrabold leading-tight">New password</h1>
                <p className="mb-6 mt-1 text-center text-sm text-muted-foreground">
                    Choose a new password for <span className="font-semibold text-foreground">{email}</span>.
                </p>

                {linkError && (
                    <Alert className="mb-4">
                        {linkError}{' '}
                        <Link href="/forgot-password" className="font-semibold underline">
                            Request a new link
                        </Link>
                    </Alert>
                )}

                <div className="space-y-1.5">
                    <Label htmlFor="password">New password</Label>
                    <div className="relative">
                        <Lock className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="password"
                            type={show ? 'text' : 'password'}
                            placeholder="At least 8 characters"
                            autoComplete="new-password"
                            autoFocus
                            className="px-10"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            aria-invalid={!!form.errors.password}
                        />
                        <button
                            type="button"
                            onClick={() => setShow((s) => !s)}
                            aria-label={show ? 'Hide passwords' : 'Show passwords'}
                            aria-pressed={show}
                            className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-2 text-muted-foreground hover:text-secondary"
                        >
                            {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                    </div>
                    {form.errors.password && <p className="text-xs font-medium text-destructive">{form.errors.password}</p>}
                </div>

                <div className="mt-4 space-y-1.5">
                    <Label htmlFor="password_confirmation">Type it again</Label>
                    <div className="relative">
                        <Lock className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="password_confirmation"
                            type={show ? 'text' : 'password'}
                            placeholder="Repeat the new password"
                            autoComplete="new-password"
                            className="pl-10"
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    disabled={form.processing}
                    className="mt-6 w-full border-b-4 border-[#c23c00] text-base active:translate-y-px active:border-b-[3px]"
                >
                    {form.processing ? 'Saving…' : 'Change password'}
                </Button>

                <Link href="/login" className="mt-4 flex items-center justify-center gap-1.5 text-sm font-semibold text-secondary hover:underline">
                    <ArrowLeft className="size-4" /> Back to sign in
                </Link>
            </AuthShell>
        </>
    );
}
