import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';

/** Success messages from the server (e.g. "password changed") on the signed-out pages: a pop-up that stays until dismissed. */
export function useFlashPopup(title = 'Done') {
    const { flash } = usePage<{ flash: { success?: string | null } }>().props;

    useEffect(() => {
        if (!flash?.success) return;
        void Swal.fire({
            icon: 'success',
            title,
            text: flash.success,
            confirmButtonText: 'OK',
            showCloseButton: true,
            allowOutsideClick: false,
            customClass: { popup: 'rounded-lg', confirmButton: 'swal-confirm' },
        });
    }, [flash?.success, title]);
}

const BUBBLES = [
    { left: '6%', size: 14, dur: '15s', delay: '0s' },
    { left: '17%', size: 8, dur: '11s', delay: '3s' },
    { left: '29%', size: 18, dur: '18s', delay: '7s' },
    { left: '44%', size: 10, dur: '13s', delay: '1s' },
    { left: '58%', size: 16, dur: '16s', delay: '5s' },
    { left: '71%', size: 9, dur: '12s', delay: '9s' },
    { left: '83%', size: 20, dur: '19s', delay: '2s' },
    { left: '93%', size: 11, dur: '14s', delay: '6s' },
];

// one 720px wave repeated four times (2880 wide), then closed along the bottom
const WAVE = 'M0 24' + 'c120 28 240 28 360 8s240-28 360-8'.repeat(4) + 'v24H0z';

/** The signed-out screen: blue background with sugar dots, the mascot on wide screens, and the card for the form. */
export default function AuthShell({ onSubmit, children }: { onSubmit: (e: React.FormEvent) => void; children: React.ReactNode }) {
    const { appName } = usePage<{ appName: string }>().props;

    return (
        <div className="relative flex min-h-screen flex-col overflow-hidden bg-secondary">
            {/* soft dotted texture, like sugar on a tray */}
            <div
                aria-hidden="true"
                className="auth-dots pointer-events-none absolute inset-0 opacity-[0.12]"
                style={{
                    backgroundImage: 'radial-gradient(#fff 1.5px, transparent 1.5px)',
                    backgroundSize: '28px 28px',
                }}
            />

            {/* sprinkles drifting up, like flour in the air */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
                {BUBBLES.map((b, i) => (
                    <span
                        key={i}
                        className="auth-bubble absolute bottom-[-40px] rounded-full bg-white/20"
                        style={{ left: b.left, width: b.size, height: b.size, ['--dur' as string]: b.dur, ['--delay' as string]: b.delay }}
                    />
                ))}
            </div>

            <main className="relative z-10 flex flex-1 items-center justify-center gap-6 px-4 py-10 lg:gap-16 xl:gap-24">
                {/* mascot: only where there is room, so the card never gets squeezed */}
                <div aria-hidden="true" className="auth-mascot-in relative hidden w-[300px] shrink-0 lg:block xl:w-[360px]">
                    <div className="auth-glow absolute left-1/2 top-1/2 size-[420px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(255,255,255,0.28),rgba(255,255,255,0.08)_65%,transparent)]" />
                    <img
                        src="/images/moymoy.png"
                        alt=""
                        className="auth-mascot relative mx-auto max-h-[68vh] w-auto object-contain drop-shadow-[0_22px_22px_rgba(3,10,70,0.55)]"
                    />
                    {/* soft ground shadow under the feet */}
                    <div className="auth-mascot-shadow mx-auto -mt-3 h-4 w-48 rounded-[50%] bg-[radial-gradient(closest-side,rgba(3,10,70,0.55),transparent)] blur-[2px]" />
                </div>

                <form
                    onSubmit={onSubmit}
                    noValidate
                    className="auth-card w-full max-w-[420px] rounded-2xl border-2 border-foreground bg-card px-7 pb-8 pt-6 shadow-[7px_7px_0_0_var(--primary),0_30px_60px_-12px_rgba(3,10,70,0.55)] sm:px-9"
                >
                    <img src="/images/logo.png" alt={appName} className="mx-auto -mb-2 -mt-2 h-40 w-40 object-contain sm:h-44 sm:w-44" />
                    {children}
                </form>
            </main>

            {/* wavy cream edge, echoing the logo's banner */}
            <div className="relative z-10 bg-secondary">
                <div className="overflow-hidden">
                    {/* the wave is drawn twice as wide and slid left by half, so it loops without a seam */}
                    <svg aria-hidden="true" viewBox="0 0 2880 48" preserveAspectRatio="none" className="auth-wave block h-9 w-[200%] fill-background sm:h-12">
                        <path d={WAVE} />
                    </svg>
                </div>
                <p className="bg-background pb-4 text-center text-xs text-muted-foreground">
                    © {new Date().getFullYear()} {appName}
                </p>
            </div>
        </div>
    );
}
