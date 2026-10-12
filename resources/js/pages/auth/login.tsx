import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Lock, Mail } from 'lucide-react';

import AuthShell, { useFlashPopup } from '@/components/auth-shell';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function Login() {
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ email: '', password: '', remember: false });
    useFlashPopup(); // e.g. "Your password has been changed"

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    const error = form.errors.email ?? form.errors.password;

    return (
        <>
            <Head title="Sign in" />
            <AuthShell onSubmit={submit}>
                <h1 className="text-center font-display text-[2rem] font-extrabold leading-tight">Welcome back</h1>
                <p className="mb-6 mt-1 text-center text-sm text-muted-foreground">Sign in to place and track your orders.</p>

                {error && <Alert className="mb-4">{error}</Alert>}

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

                <div className="mt-4 space-y-1.5">
                    <Label htmlFor="password">Password</Label>
                    <div className="relative">
                        <Lock className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            placeholder="••••••••"
                            autoComplete="current-password"
                            className="px-10"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword((s) => !s)}
                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                            aria-pressed={showPassword}
                            className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-2 text-muted-foreground hover:text-secondary"
                        >
                            {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                    </div>
                </div>

                <div className="mb-6 mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <label className="flex w-fit cursor-pointer items-center gap-2 text-sm">
                        <Checkbox checked={form.data.remember} onCheckedChange={(v) => form.setData('remember', v === true)} />
                        Keep me signed in
                    </label>
                    <Link href="/forgot-password" className="text-sm font-semibold text-secondary hover:underline">
                        Forgot password?
                    </Link>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    disabled={form.processing}
                    className="w-full border-b-4 border-[#c23c00] text-base active:translate-y-px active:border-b-[3px]"
                >
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </AuthShell>
        </>
    );
}
