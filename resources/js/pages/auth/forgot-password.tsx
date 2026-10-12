import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Mail } from 'lucide-react';

import AuthShell, { useFlashPopup } from '@/components/auth-shell';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    useFlashPopup('Check your email'); // the server's "we have sent a link" answer

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Forgot password" />
            <AuthShell onSubmit={submit}>
                <h1 className="text-center font-display text-[2rem] font-extrabold leading-tight">Forgot password?</h1>
                <p className="mb-6 mt-1 text-center text-sm text-muted-foreground">
                    Enter the email you sign in with and we will send you a link to choose a new password.
                </p>

                {form.errors.email && <Alert className="mb-4">{form.errors.email}</Alert>}

                <div className="space-y-1.5">
                    <Label htmlFor="email">Email</Label>
                    <div className="relative">
                        <Mail className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="email"
                            type="email"
                            placeholder="you@bwbakeshop.com"
                            autoComplete="username"
                            autoFocus
                            className="pl-10"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            aria-invalid={!!form.errors.email}
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    disabled={form.processing}
                    className="mt-6 w-full border-b-4 border-[#c23c00] text-base active:translate-y-px active:border-b-[3px]"
                >
                    {form.processing ? 'Sending…' : 'Send reset link'}
                </Button>

                <p className="mt-4 text-center text-xs text-muted-foreground">
                    No access to that email? Ask your admin to reset your password for you.
                </p>

                <Link href="/login" className="mt-4 flex items-center justify-center gap-1.5 text-sm font-semibold text-secondary hover:underline">
                    <ArrowLeft className="size-4" /> Back to sign in
                </Link>
            </AuthShell>
        </>
    );
}
