import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, Lock, Mail } from 'lucide-react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function Login() {
    const { appName } = usePage<{ appName: string }>().props;
    const [showPassword, setShowPassword] = useState(false);
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    const error = form.errors.email ?? form.errors.password;

    return (
        <>
            <Head title="Sign in" />
            <div className="relative flex min-h-screen flex-col overflow-hidden bg-secondary">
                {/* soft dotted texture, like sugar on a tray */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 opacity-[0.12]"
                    style={{
                        backgroundImage: 'radial-gradient(#fff 1.5px, transparent 1.5px)',
                        backgroundSize: '28px 28px',
                    }}
                />

                <main className="relative z-10 flex flex-1 items-center justify-center gap-6 px-4 py-10 lg:gap-16 xl:gap-24">
                    {/* mascot: only where there is room, so the sign-in card never gets squeezed */}
                    <div aria-hidden="true" className="relative hidden w-[300px] shrink-0 lg:block xl:w-[360px]">
                        <div className="absolute left-1/2 top-1/2 size-[420px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(255,255,255,0.28),rgba(255,255,255,0.08)_65%,transparent)]" />
                        <img
                            src="/images/moymoy.png"
                            alt=""
                            className="relative mx-auto max-h-[68vh] w-auto object-contain drop-shadow-[0_22px_22px_rgba(3,10,70,0.55)]"
                        />
                        {/* soft ground shadow under the feet */}
                        <div className="mx-auto -mt-3 h-4 w-48 rounded-[50%] bg-[radial-gradient(closest-side,rgba(3,10,70,0.55),transparent)] blur-[2px]" />
                    </div>

                    <form
                        onSubmit={submit}
                        noValidate
                        className="w-full max-w-[420px] rounded-2xl border-2 border-foreground bg-card px-7 pb-8 pt-6 shadow-[7px_7px_0_0_var(--primary),0_30px_60px_-12px_rgba(3,10,70,0.55)] sm:px-9"
                    >
                        <img src="/images/logo.png" alt={appName} className="mx-auto -mb-1 h-32 w-32 object-contain" />

                        <h1 className="text-center font-display text-[2rem] font-extrabold leading-tight">
                            Welcome back
                        </h1>
                        <p className="mb-6 mt-1 text-center text-sm text-muted-foreground">
                            Sign in to place and track your orders.
                        </p>

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

                        <label className="mb-6 mt-4 flex w-fit cursor-pointer items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.remember}
                                onCheckedChange={(v) => form.setData('remember', v === true)}
                            />
                            Keep me signed in
                        </label>

                        <Button
                            type="submit"
                            size="lg"
                            disabled={form.processing}
                            className="w-full border-b-4 border-[#c23c00] text-base active:translate-y-px active:border-b-[3px]"
                        >
                            {form.processing ? 'Signing in…' : 'Sign in'}
                        </Button>
                    </form>
                </main>

                {/* wavy cream edge, echoing the logo's banner */}
                <div className="relative z-10 bg-secondary">
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 1440 48"
                        preserveAspectRatio="none"
                        className="block h-9 w-full fill-background sm:h-12"
                    >
                        <path d="M0 24c120 28 240 28 360 8s240-28 360-8 240 28 360 8 240-28 360-8v24H0z" />
                    </svg>
                    <p className="bg-background pb-4 text-center text-xs text-muted-foreground">
                        © {new Date().getFullYear()} {appName}
                    </p>
                </div>
            </div>
        </>
    );
}
