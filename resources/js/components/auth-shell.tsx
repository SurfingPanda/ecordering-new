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

/** The signed-out screen: blue background with sugar dots, the mascot on wide screens, and the card for the form. */
export default function AuthShell({ onSubmit, children }: { onSubmit: (e: React.FormEvent) => void; children: React.ReactNode }) {
    const { appName } = usePage<{ appName: string }>().props;

    return (
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
                {/* mascot: only where there is room, so the card never gets squeezed */}
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
                    onSubmit={onSubmit}
                    noValidate
                    className="w-full max-w-[420px] rounded-2xl border-2 border-foreground bg-card px-7 pb-8 pt-6 shadow-[7px_7px_0_0_var(--primary),0_30px_60px_-12px_rgba(3,10,70,0.55)] sm:px-9"
                >
                    <img src="/images/logo.png" alt={appName} className="mx-auto -mb-1 h-32 w-32 object-contain" />
                    {children}
                </form>
            </main>

            {/* wavy cream edge, echoing the logo's banner */}
            <div className="relative z-10 bg-secondary">
                <svg aria-hidden="true" viewBox="0 0 1440 48" preserveAspectRatio="none" className="block h-9 w-full fill-background sm:h-12">
                    <path d="M0 24c120 28 240 28 360 8s240-28 360-8 240 28 360 8 240-28 360-8v24H0z" />
                </svg>
                <p className="bg-background pb-4 text-center text-xs text-muted-foreground">
                    © {new Date().getFullYear()} {appName}
                </p>
            </div>
        </div>
    );
}
