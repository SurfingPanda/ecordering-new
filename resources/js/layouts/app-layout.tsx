import { useEffect, useState, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import {
    Archive,
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    LayoutDashboard,
    Loader2,
    LogOut,
    Menu,
    CalendarCheck,
    Package,
    PackageX,
    ShoppingBag,
    ReceiptText,
    Settings2,
    ShoppingCart,
    Store,
    TableProperties,
    Tags,
    Warehouse,
    X,
    type LucideIcon,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { NewOrderProvider, useNewOrder } from '@/components/new-order-button';
import { formatTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DeadlineInfo, OrderingToday, SharedProps } from '@/types';

interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    badge?: number; // a small count beside the label (e.g. stores that haven't ordered yet)
}

/** Related pages are grouped under a small heading so the menu reads in sections instead of one long list. */
interface NavGroup {
    label?: string;
    items: NavItem[];
}

const adminNav: NavGroup[] = [
    { items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }] },
    {
        label: 'Orders',
        items: [
            { label: 'Today’s Orders', href: '/admin/today', icon: CalendarCheck },
            { label: 'All Store Orders', href: '/admin/consolidated', icon: TableProperties },
            { label: 'Orders (TRs)', href: '/orders', icon: ReceiptText },
        ],
    },
    {
        label: 'Catalog',
        items: [
            { label: 'Product Catalog', href: '/retails', icon: Package },
            { label: 'Archived Products', href: '/admin/archived-products', icon: Archive },
            { label: 'Categories', href: '/admin/categories', icon: Tags },
        ],
    },
    {
        label: 'Manage',
        items: [
            { label: 'Stores', href: '/admin/stores', icon: Store },
            { label: 'Order Settings', href: '/admin/settings', icon: Settings2 },
        ],
    },
];

const storeNav: NavGroup[] = [
    { items: [{ label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }] },
    {
        label: 'Ordering',
        items: [
            { label: 'Place an Order', href: '/orders/create', icon: ShoppingCart },
            { label: 'My Orders', href: '/orders', icon: ReceiptText },
        ],
    },
    {
        label: 'Catalog',
        items: [
            { label: 'BW Products', href: '/retails?source=bw_products', icon: Package },
            { label: 'Warehouse Items', href: '/retails?source=warehouse', icon: Warehouse },
            { label: 'Merchandise', href: '/retails?source=merchandise', icon: ShoppingBag },
            { label: 'Rejects', href: '/retails?source=rejects', icon: PackageX },
        ],
    },
];

const STORAGE_KEY = 'sidebar-collapsed';

function readCollapsed() {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

interface Props {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}

/** Every page is wrapped in the new-order flow, so any button (or the sidebar link) can start it. */
export default function AppLayout(props: Props) {
    return (
        <NewOrderProvider>
            <Shell {...props} />
        </NewOrderProvider>
    );
}

function Shell({ title, actions, children }: Props) {
    const { appName, auth, flash, deadline, ordering_today } = usePage<SharedProps>().props;
    const { start: startNewOrder } = useNewOrder();
    const { url } = usePage();
    const [open, setOpen] = useState(false); // mobile drawer
    const [signOutOpen, setSignOutOpen] = useState(false);

    // success messages from the server (order placed/posted, store saved, ...) appear as a pop-up that stays until dismissed
    useEffect(() => {
        if (!flash.success) return;
        void Swal.fire({
            icon: 'success',
            title: 'Done',
            text: flash.success,
            confirmButtonText: 'OK',
            showCloseButton: true,
            allowOutsideClick: false,
            customClass: { popup: 'rounded-lg', confirmButton: 'swal-confirm' },
        });
    }, [flash.success]);
    const [signingOut, setSigningOut] = useState(false);
    const [collapsed, setCollapsed] = useState(readCollapsed); // desktop rail
    const path = url.split('?')[0];
    const baseNav = auth.user.role === 'admin' ? adminNav : storeNav;
    const waiting = ordering_today && ordering_today.open ? ordering_today.not_ordered : 0;
    const nav = baseNav.map((g) => ({ ...g, items: g.items.map((i) => (i.href === '/admin/today' && waiting > 0 ? { ...i, badge: waiting } : i)) }));

    useEffect(() => {
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch {
            /* storage unavailable: the preference just won't persist */
        }
    }, [collapsed]);

    const isActive = (href: string) => {
        const [hrefPath, hrefQuery] = href.split('?');
        if (hrefQuery) return path === hrefPath && url.includes(hrefQuery);
        return path === hrefPath || path.startsWith(hrefPath + '/');
    };

    // compact = icon-only rail (desktop only; the mobile drawer is always full width)
    const renderSidebar = (compact: boolean) => {
        const renderItem = ({ label, href, icon: Icon, badge }: NavItem) => {
            const active = isActive(href);
            const itemClass = cn(
                'group relative flex w-full items-center rounded-lg py-2 text-[15px] font-medium transition-colors',
                compact ? 'justify-center px-0' : 'gap-3 px-2.5',
                active ? 'bg-white/[0.14] text-white shadow-[inset_0_0_0_1px_rgba(255,255,255,0.1)]' : 'text-white/70 hover:bg-white/[0.08] hover:text-white',
            );
            const inner = (
                <>
                    {/* orange marker on the page you're on */}
                    {active && <span aria-hidden="true" className="absolute -left-3 top-1/2 h-7 w-1 -translate-y-1/2 rounded-r-full bg-[#ff5000]" />}
                    <span
                        className={cn(
                            'grid size-8 shrink-0 place-items-center rounded-md transition-colors',
                            active ? 'bg-[#ff5000] text-white shadow-md shadow-black/20' : 'bg-white/[0.08] text-white/80 group-hover:bg-white/15 group-hover:text-white',
                        )}
                    >
                        <Icon className="size-[18px]" />
                    </span>
                    <span className={cn(compact && 'sr-only')}>{label}</span>
                    {badge ? (
                        <span className={cn('rounded-full bg-[#ff5000] px-1.5 text-[11px] font-semibold tabular-nums text-white', compact ? 'absolute right-1 top-1' : 'ml-auto')} title={`${badge} ${badge === 1 ? "store hasn’t" : "stores haven’t"} ordered yet`}>
                            {badge}
                        </span>
                    ) : null}
                </>
            );

            // "Place an Order" goes through the same prompt as the New order buttons (it checks for an existing TR first)
            if (href === '/orders/create') {
                return (
                    <button
                        key={href}
                        type="button"
                        title={compact ? label : undefined}
                        onClick={() => {
                            setOpen(false);
                            startNewOrder();
                        }}
                        aria-current={active ? 'page' : undefined}
                        className={itemClass}
                    >
                        {inner}
                    </button>
                );
            }

            return (
                <Link key={href} href={href} title={compact ? label : undefined} onClick={() => setOpen(false)} aria-current={active ? 'page' : undefined} className={itemClass}>
                    {inner}
                </Link>
            );
        };

        const initial = auth.user.name.slice(0, 1);
        const roleLabel = auth.user.role === 'admin' ? 'Administrator' : (auth.user.store?.name ?? auth.user.email);

        return (
            <div className="relative flex h-full flex-col overflow-hidden bg-gradient-to-b from-[#06166f] via-[#0a2096] to-[#0b30c8] text-white">
                {/* depth: a faint dotted texture and a soft glow behind the logo */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 opacity-[0.08]"
                    style={{ backgroundImage: 'radial-gradient(#fff 1px, transparent 1px)', backgroundSize: '20px 20px' }}
                />
                <div aria-hidden="true" className="pointer-events-none absolute -top-16 left-1/2 size-80 -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(130,160,255,0.5),transparent)]" />

                <Link href="/dashboard" aria-label={`${appName} dashboard`} className={cn('relative flex justify-center', compact ? 'px-2 py-4' : 'px-4 pb-3 pt-5')}>
                    <img src="/images/logo.png" alt={appName} className={cn('object-contain drop-shadow-[0_6px_14px_rgba(0,0,40,0.35)] transition-all', compact ? 'size-12' : 'size-40')} />
                </Link>
                <div aria-hidden="true" className="relative mx-5 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent" />

                <nav className="relative flex-1 overflow-y-auto px-3 py-4" aria-label="Main">
                    {nav.map((group, i) => (
                        <div key={group.label ?? 'top'} className={cn(i > 0 && 'mt-5')}>
                            {group.label &&
                                (compact ? (
                                    <div aria-hidden="true" className="mx-3 mb-2 h-px bg-white/15" />
                                ) : (
                                    <p className="mb-1.5 px-2.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-white/45">{group.label}</p>
                                ))}
                            <div className="space-y-1">{group.items.map(renderItem)}</div>
                        </div>
                    ))}
                </nav>

                {/* who is signed in */}
                <div className="relative p-3">
                    {compact ? (
                        <div className="flex flex-col items-center gap-2">
                            <Link href="/account" title={`My account — ${auth.user.name} (${auth.user.email})`} className="grid size-9 place-items-center rounded-full bg-[#ff5000] text-sm font-semibold uppercase shadow-md shadow-black/20">
                                {initial}
                            </Link>
                            <button
                                type="button"
                                onClick={() => setSignOutOpen(true)}
                                title="Sign out"
                                aria-label="Sign out"
                                className="grid size-9 place-items-center rounded-lg text-white/70 transition-colors hover:bg-white/15 hover:text-white"
                            >
                                <LogOut className="size-[18px]" />
                            </button>
                        </div>
                    ) : (
                        <div className="flex items-center gap-3 rounded-xl bg-white/10 p-2.5 ring-1 ring-inset ring-white/10 backdrop-blur-sm">
                            <Link href="/account" onClick={() => setOpen(false)} title="My account — change your password" className="flex min-w-0 flex-1 items-center gap-3 rounded-lg hover:opacity-90">
                                <div className="grid size-10 shrink-0 place-items-center rounded-full bg-[#ff5000] text-sm font-semibold uppercase shadow-md shadow-black/20">{initial}</div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold leading-tight">{auth.user.name}</p>
                                    <p className="truncate text-xs text-white/65">{roleLabel}</p>
                                </div>
                            </Link>
                            <button
                                type="button"
                                onClick={() => setSignOutOpen(true)}
                                title="Sign out"
                                aria-label="Sign out"
                                className="grid size-9 shrink-0 place-items-center rounded-lg text-white/70 transition-colors hover:bg-white/15 hover:text-white"
                            >
                                <LogOut className="size-[18px]" />
                            </button>
                        </div>
                    )}
                </div>
            </div>
        );
    };

    return (
        <>
            <Head title={title} />
            <div
                className={cn(
                    'min-h-screen bg-[#f2f4f8] lg:grid lg:transition-[grid-template-columns] lg:duration-200',
                    collapsed ? 'lg:grid-cols-[68px_1fr]' : 'lg:grid-cols-[232px_1fr]',
                )}
            >
                <aside className="sticky top-0 z-40 hidden h-screen shadow-[4px_0_24px_-6px_rgba(6,22,111,0.5)] lg:block">
                    {renderSidebar(collapsed)}
                    <button
                        type="button"
                        onClick={() => setCollapsed((c) => !c)}
                        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        aria-expanded={!collapsed}
                        className="absolute -right-3 top-5 grid size-6 place-items-center rounded-full border border-border bg-white text-foreground shadow-sm hover:bg-muted"
                    >
                        {collapsed ? <ChevronRight className="size-3.5" /> : <ChevronLeft className="size-3.5" />}
                    </button>
                </aside>

                {open && (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
                        <aside className="absolute inset-y-0 left-0 w-[250px]">{renderSidebar(false)}</aside>
                    </div>
                )}

                <div className="min-w-0">
                    <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-border bg-white px-4 sm:px-6">
                        <button
                            type="button"
                            className="rounded-md p-2 hover:bg-muted lg:hidden"
                            onClick={() => setOpen((o) => !o)}
                            aria-label={open ? 'Close menu' : 'Open menu'}
                        >
                            {open ? <X className="size-5" /> : <Menu className="size-5" />}
                        </button>
                        <h1 className="flex-1 truncate text-lg font-semibold">{title}</h1>
                        {actions}
                    </header>

                    <main className="p-4 sm:p-6">
                        <DeadlineReminder role={auth.user.role} userId={auth.user.id} deadline={deadline} today={ordering_today} onPlaceOrder={startNewOrder} />
                        {auth.user.role === 'store' && deadline && typeof deadline.store_active === 'boolean' && <DeadlineNotice deadline={deadline} storeName={auth.user.store?.name} userId={auth.user.id} />}
                        {children}
                    </main>
                </div>
            </div>
            <Dialog open={signOutOpen} onOpenChange={(o) => !signingOut && setSignOutOpen(o)}>
                <DialogContent className="max-w-sm p-0">
                    <div className="px-6 pb-2 pt-6 text-center">
                        <div className="mx-auto mb-3 grid size-11 place-items-center rounded-full bg-[#e8ecfb] text-secondary">
                            <LogOut className="size-5" />
                        </div>
                        <DialogTitle className="text-base">Sign out?</DialogTitle>
                        <DialogDescription className="mt-1.5 leading-relaxed">You will need to sign in again to place or view orders.</DialogDescription>
                    </div>
                    <div className="flex gap-2 px-6 pb-6 pt-4">
                        <Button type="button" variant="ghost" className="h-10 flex-1 border border-border" disabled={signingOut} onClick={() => setSignOutOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-10 flex-1"
                            disabled={signingOut}
                            onClick={() => router.post('/logout', {}, { onStart: () => { setSigningOut(true); }, onFinish: () => setSigningOut(false) })}
                        >
                            {signingOut ? (
                                <>
                                    <Loader2 className="animate-spin" /> Signing out…
                                </>
                            ) : (
                                'Sign out'
                            )}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

const DEADLINE_SEEN = 'deadline-seen:';

/** Store accounts: a pop-up with today's submission deadline (and a live "closes in"), shown once per day (until "Got it" is clicked), even across sign-outs. */
function DeadlineNotice({ deadline, storeName, userId }: { deadline: DeadlineInfo; storeName?: string; userId: number }) {
    const [now, setNow] = useState(() => Date.now());
    const [dismissed, setDismissed] = useState<string | null>(null);

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(t);
    }, []);

    const label = formatTime(deadline.time);
    const sourceNote = deadline.source === 'date' ? 'extended for today' : deadline.source === 'store' ? 'your store’s deadline' : 'standard deadline';
    const msLeft = new Date(deadline.at).getTime() - now;

    const state: 'inactive' | 'closed' | 'open' = !deadline.store_active ? 'inactive' : !deadline.open || msLeft < 0 ? 'closed' : 'open';
    // once per user per day: remembered in this browser, and not cleared by signing out
    const key = `${DEADLINE_SEEN}${userId}:${deadline.at.slice(0, 10)}`;

    let seen = false;
    try {
        seen = localStorage.getItem(key) === '1';
    } catch {
        /* storage unavailable: just show it until dismissed */
    }
    const visible = !seen && dismissed !== key;

    const close = () => {
        setDismissed(key);
        try {
            localStorage.setItem(key, '1');
        } catch {
            /* ignore */
        }
    };

    const mins = Math.max(1, Math.round(msLeft / 60_000));
    const left = mins >= 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${mins}m`;
    const soon = state === 'open' && mins <= 30;
    const bad = state !== 'open';

    return (
        <Dialog open={visible} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-w-sm p-0">
                <div className="px-6 pb-2 pt-6 text-center">
                    <div className={cn('mx-auto mb-3 grid size-12 place-items-center rounded-full', bad ? 'bg-[#ffe9e2] text-destructive' : soon ? 'bg-[#fff1cf] text-[#9a5b00]' : 'bg-[#e8ecfb] text-secondary')}>
                        {bad ? <AlertTriangle className="size-6" /> : <Clock className="size-6" />}
                    </div>
                    {state === 'inactive' ? (
                        <>
                            <DialogTitle className="text-base">{storeName ?? 'Your store'} is deactivated</DialogTitle>
                            <DialogDescription className="mt-1.5 leading-relaxed">You can still view your order history, but new orders can’t be submitted. Please contact an admin.</DialogDescription>
                        </>
                    ) : state === 'closed' ? (
                        <>
                            <DialogTitle className="text-base">Today’s deadline has passed</DialogTitle>
                            <DialogDescription className="mt-1.5 leading-relaxed">
                                The cutoff was <strong>{label}</strong>. New orders can’t be placed or posted until tomorrow, unless an admin extends your deadline.
                            </DialogDescription>
                        </>
                    ) : (
                        <>
                            <DialogTitle className="text-base">Submission deadline today</DialogTitle>
                            <p className="mt-2 text-3xl font-semibold tabular-nums">{label}</p>
                            <DialogDescription className="mt-1 leading-relaxed">
                                {sourceNote.charAt(0).toUpperCase() + sourceNote.slice(1)} — closes in <strong>{left}</strong>.
                            </DialogDescription>
                        </>
                    )}
                </div>
                <div className="px-6 pb-6 pt-4">
                    <Button type="button" variant="secondary" className="h-10 w-full" onClick={close}>
                        Got it
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Minutes before the cutoff at which a reminder pops up (once each, per person per day). */
const REMINDER_AT = [30, 10];

/**
 * A reminder shortly before the cutoff:
 *  - store accounts that haven't placed today's order yet are told how long is left, with a button to start it;
 *  - admins are told how many stores haven't ordered yet, with a button to see which.
 * Each reminder shows once per threshold per day (remembered in this browser) and goes away on its own once the order is placed.
 */
function DeadlineReminder({
    role,
    userId,
    deadline,
    today,
    onPlaceOrder,
}: {
    role: 'admin' | 'store';
    userId: number;
    deadline: DeadlineInfo | null;
    today: OrderingToday | null;
    onPlaceOrder: () => void;
}) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(t);
    }, []);

    const store = role === 'store' && deadline && deadline.store_active && deadline.open && !deadline.ordered_today ? deadline : null;
    const admin = role === 'admin' && today && today.open && today.not_ordered > 0 ? today : null;
    const at = store ? store.at : admin ? admin.deadline_at : null;
    const day = at ? at.slice(0, 10) : '';
    const minsLeft = at ? Math.ceil((new Date(at).getTime() - now) / 60_000) : null;

    useEffect(() => {
        if (minsLeft === null || minsLeft <= 0) return;
        // the lowest threshold we've crossed, so a late sign-in gets one reminder, not two
        const threshold = [...REMINDER_AT].sort((a, b) => a - b).find((t) => minsLeft <= t);
        if (!threshold) return;

        const key = `deadline-reminder:${userId}:${day}:${threshold}`;
        try {
            if (localStorage.getItem(key) === '1') return;
            // wait until today's deadline pop-up has been dismissed so two pop-ups never stack
            if (role === 'store' && localStorage.getItem(`${DEADLINE_SEEN}${userId}:${day}`) !== '1') return;
            localStorage.setItem(key, '1');
        } catch {
            /* storage unavailable: still remind, once per page load */
        }

        const left = minsLeft >= 60 ? `${Math.floor(minsLeft / 60)}h ${minsLeft % 60}m` : `${minsLeft} min`;
        void Swal.fire(
            store
                ? {
                      icon: 'warning',
                      title: 'Order deadline is close',
                      html: `You haven’t placed today’s order yet. The cutoff is <b>${formatTime(store.time)}</b> — about <b>${left}</b> left.`,
                      confirmButtonText: 'Place my order now',
                      showCancelButton: true,
                      cancelButtonText: 'Later',
                      showCloseButton: true,
                      allowOutsideClick: false,
                      customClass: { confirmButton: 'swal-confirm' },
                  }
                : {
                      icon: 'warning',
                      title: `${admin!.not_ordered} ${admin!.not_ordered === 1 ? 'store hasn’t' : 'stores haven’t'} ordered yet`,
                      html: `The standard cutoff is <b>${formatTime(admin!.deadline_time)}</b> — about <b>${left}</b> left.`,
                      confirmButtonText: 'See who',
                      showCancelButton: true,
                      cancelButtonText: 'Dismiss',
                      showCloseButton: true,
                      allowOutsideClick: false,
                      customClass: { confirmButton: 'swal-confirm' },
                  },
        ).then((r) => {
            if (!r.isConfirmed) return;
            if (store) onPlaceOrder();
            else router.visit('/admin/today');
        });
    }, [minsLeft, userId, day, role, store, admin, onPlaceOrder]);

    return null;
}
