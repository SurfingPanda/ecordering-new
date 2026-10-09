import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, Plus } from 'lucide-react';

import { StatusBadge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDay } from '@/lib/format';
import { field } from '@/lib/ui';
import type { OrderStatus } from '@/types';

function csrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Today ± one year, as YYYY-MM-DD, built from the server's idea of "today". */
function shiftYear(iso: string, years: number) {
    const d = new Date(iso + 'T00:00:00');
    d.setFullYear(d.getFullYear() + years);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

interface ExistingOrder {
    id: number;
    number: string;
    status: OrderStatus;
    order_date: string;
    url: string;
}

const NewOrderContext = createContext<{ start: (date?: string) => void }>({ start: () => {} });

/** Starts the "new order" flow from anywhere inside the app layout (buttons, the sidebar link). */
export const useNewOrder = () => useContext(NewOrderContext);

/**
 * The new-order flow, mounted once for the whole layout:
 *   1. ask the server for a TR number for a date (today unless another date is asked for),
 *   2. if the store already has a TR for that date -> "You already have an order" (a store can only place one per day),
 *   3. otherwise show the reserved TR number with the date picker, and Continue opens the order sheet.
 */
export function NewOrderProvider({ children }: { children: ReactNode }) {
    // step 3: the prompt
    const [open, setOpen] = useState(false);
    const [number, setNumber] = useState<string | null>(null);
    const [today, setToday] = useState<string | null>(null);
    const [date, setDate] = useState('');
    const [error, setError] = useState<string | null>(null); // why an ID couldn't be reserved (deadline passed, store deactivated, ...)
    const [dateError, setDateError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // step 2: the store already has an order for that day
    const [existing, setExisting] = useState<{ order: ExistingOrder; today: boolean } | null>(null);

    const start = useCallback(async (requestedDate?: string) => {
        // already filling in an order: there is nothing to start, stay on it
        if (!requestedDate && window.location.pathname === '/orders/create') return;

        setExisting(null);
        setNumber(null);
        setError(null);
        setDateError(null);

        try {
            const res = await fetch('/orders/draft' + (requestedDate ? `?date=${requestedDate}` : ''), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            });

            if (res.status === 409) {
                const body = await res.json();
                setOpen(false);
                setExisting({ order: body.order, today: !requestedDate });
                return;
            }
            if (!res.ok) {
                const body = await res.json().catch(() => null);
                throw new Error(body?.errors?.deadline?.[0] ?? body?.message ?? 'Couldn’t reserve an ID. Please try again.');
            }

            const data = await res.json();
            if (data.resume) {
                setOpen(false);
                router.visit('/orders/create');
                return;
            }
            setOpen(true);
            setNumber(data.number);
            setToday((t) => t ?? data.order_date); // remember the server's "today" for the date limits
            setDate(data.order_date);
        } catch (e) {
            setOpen(true);
            setError(e instanceof Error ? e.message : 'Couldn’t reserve an ID. Please try again.');
        }
    }, []);

    const proceed = () => {
        setSaving(true);
        setDateError(null);
        router.put(
            '/orders/draft',
            { order_date: date },
            {
                onError: (errors) => setDateError(errors.deadline ?? errors.order_date ?? 'Please choose a valid date.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const value = useMemo(() => ({ start }), [start]);

    return (
        <NewOrderContext.Provider value={value}>
            {children}

            {/* "You already have an order" */}
            <Dialog open={existing !== null} onOpenChange={(o) => !o && setExisting(null)}>
                <DialogContent className="max-w-sm p-0">
                    {existing && (
                        <>
                            <div className="px-6 pb-2 pt-6 text-center">
                                <div className="mx-auto mb-3 grid size-11 place-items-center rounded-full bg-[#e8ecfb] text-secondary">
                                    <CalendarCheck className="size-5" />
                                </div>
                                <DialogTitle className="text-base">
                                    You already have an order {existing.today ? 'today' : `for ${formatDay(existing.order.order_date)}`}
                                </DialogTitle>
                                <DialogDescription className="mt-1.5 leading-relaxed">A store can only place one order (TR) per day.</DialogDescription>

                                <dl className="mt-4 grid grid-cols-[88px_1fr] items-center gap-x-3 gap-y-2 rounded-md border border-border bg-[#f2f4f8] px-4 py-3 text-left text-[13px]">
                                    <dt className="text-muted-foreground">TR number</dt>
                                    <dd className="font-mono text-sm font-semibold tracking-wide">{existing.order.number}</dd>
                                    <dt className="text-muted-foreground">Order date</dt>
                                    <dd className="font-medium">{formatDay(existing.order.order_date)}</dd>
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <StatusBadge status={existing.order.status} />
                                    </dd>
                                </dl>

                                <p className="mt-3 text-left text-xs leading-relaxed text-muted-foreground">
                                    {existing.order.status === 'posted'
                                        ? 'It has already been posted. If it needs to be redone, ask an admin to cancel it — then you can place a new order.'
                                        : 'It is still pending. Open it to post it, or cancel it if you need to start over.'}
                                </p>
                            </div>

                            <div className="flex gap-2 px-6 pb-6 pt-4">
                                <Button type="button" variant="ghost" className="h-10 flex-1 border border-border" onClick={() => setExisting(null)}>
                                    Close
                                </Button>
                                <Button asChild variant="secondary" className="h-10 flex-1">
                                    <Link href={existing.order.url} onClick={() => setExisting(null)}>
                                        View order
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            {/* the TR number + date prompt */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogTitle className="text-center">Start a new order?</DialogTitle>
                    <DialogDescription className="text-center">Your order will be saved under this ID.</DialogDescription>

                    <div className="my-5 rounded-md border border-border bg-[#f2f4f8] px-4 py-4 text-center">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Order ID</p>
                        {error ? (
                            <p className="mt-1 text-sm font-medium text-destructive">{error}</p>
                        ) : (
                            <p className="mt-1 min-h-8 font-mono text-xl font-semibold tracking-wider" aria-live="polite">
                                {number ?? '…'}
                            </p>
                        )}
                    </div>

                    {!error && (
                        <div className="mb-5 space-y-1.5">
                            <Label htmlFor="order_date" className="text-[13px]">
                                Order date
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="order_date"
                                    type="date"
                                    value={date}
                                    min={today ? shiftYear(today, -1) : undefined}
                                    max={today ? shiftYear(today, 1) : undefined}
                                    disabled={!today || !number}
                                    onChange={(e) => setDate(e.target.value)}
                                    aria-invalid={!!dateError}
                                    className={field}
                                />
                                {today && date !== today && (
                                    <Button type="button" variant="ghost" size="sm" className="shrink-0 border border-border" onClick={() => setDate(today)}>
                                        Today
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">{!today || date === today ? "Filled in with today's date. Change it if the order is for another day." : 'Pick the day this order is for. Each store can only place one order per day.'}</p>
                            {dateError && <p className="text-xs font-medium text-destructive">{dateError}</p>}
                        </div>
                    )}

                    <div className="flex justify-center gap-2">
                        <Button variant="ghost" size="sm" className="border border-border" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        {error ? (
                            <Button variant="secondary" size="sm" onClick={() => start()}>
                                Check again
                            </Button>
                        ) : (
                            <Button variant="secondary" size="sm" disabled={!number || !date || saving} onClick={proceed}>
                                Continue
                            </Button>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </NewOrderContext.Provider>
    );
}

/** The "New order" button used in page headers. */
export default function NewOrderButton() {
    const { start } = useNewOrder();

    return (
        <Button size="sm" variant="secondary" onClick={() => start()}>
            <Plus /> New order
        </Button>
    );
}
