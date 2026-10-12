import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { selectCls } from '@/lib/ui';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Props {
    data: Paginated<unknown>;
    /** Rows per page choices; the selector is hidden when this is left out. */
    perPage?: { value: number; options: number[]; onChange: (n: number) => void };
}

/** Page numbers to show: first, last, and a window around the current page, with gaps as null. */
function pageList(current: number, last: number): (number | null)[] {
    const wanted = new Set([1, last, current - 1, current, current + 1]);
    const pages = [...wanted].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
    const out: (number | null)[] = [];
    pages.forEach((p, i) => {
        if (i > 0 && p - pages[i - 1] > 1) out.push(null);
        out.push(p);
    });
    return out;
}

/**
 * Responsive pagination for a Laravel paginator. Phones get a roomy Previous / "Page 2 of 9" / Next bar; wider screens
 * get numbered pages (first, last and the ones around the current page). Links keep every active filter.
 */
export function Pagination({ data, perPage }: Props) {
    const current = data.current_page ?? 1;
    const last = data.last_page ?? 1;
    const base = data.links.find((l) => l.url)?.url;

    const hrefFor = (page: number): string | null => {
        if (!base || page < 1 || page > last) return null;
        const url = new URL(base, window.location.origin);
        url.searchParams.set('page', String(page));
        return url.pathname + url.search;
    };

    const prev = hrefFor(current - 1);
    const next = hrefFor(current + 1);
    const btn = 'inline-flex h-10 items-center justify-center gap-1 rounded-md border px-3 text-sm font-medium sm:h-8 sm:min-w-8 sm:px-2 sm:text-xs';
    const idle = 'border-border bg-white hover:bg-muted';
    const off = 'pointer-events-none border-transparent opacity-40';

    return (
        <nav aria-label="Pagination" className="flex flex-col gap-3 border-t border-border px-4 py-3 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center justify-between gap-3 sm:justify-start sm:gap-4">
                <p>{data.total === 0 ? 'No entries' : `Showing ${data.from}–${data.to} of ${data.total}`}</p>
                {perPage && (
                    <label className="flex items-center gap-1.5 text-xs">
                        <span className="max-sm:sr-only">Rows</span>
                        <select aria-label="Rows per page" className={cn(selectCls, 'h-8 text-xs')} value={perPage.value} onChange={(e) => perPage.onChange(Number(e.target.value))}>
                            {perPage.options.map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
            </div>

            {last > 1 && (
                <>
                    {/* phones and small tablets */}
                    <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2 sm:hidden">
                        <PageLink href={prev} className={cn(btn, 'justify-self-start', prev ? idle : off)} label="Previous page">
                            <ChevronLeft className="size-4" /> Prev
                        </PageLink>
                        <span className="text-center text-[13px] tabular-nums">
                            Page <span className="font-semibold text-foreground">{current}</span> of {last}
                        </span>
                        <PageLink href={next} className={cn(btn, 'justify-self-end', next ? idle : off)} label="Next page">
                            Next <ChevronRight className="size-4" />
                        </PageLink>
                    </div>

                    {/* tablets and desktops */}
                    <div className="hidden flex-wrap items-center gap-1 sm:flex">
                        <PageLink href={prev} className={cn(btn, prev ? idle : off)} label="Previous page">
                            <ChevronLeft className="size-3.5" /> <span className="hidden md:inline">Previous</span>
                        </PageLink>
                        {pageList(current, last).map((p, i) =>
                            p === null ? (
                                <span key={`gap-${i}`} aria-hidden="true" className="px-1 text-muted-foreground/60">
                                    …
                                </span>
                            ) : (
                                <PageLink
                                    key={p}
                                    href={hrefFor(p)}
                                    label={`Page ${p}`}
                                    current={p === current}
                                    className={cn(btn, 'tabular-nums', p === current ? 'border-secondary bg-secondary text-secondary-foreground' : idle)}
                                >
                                    {p}
                                </PageLink>
                            ),
                        )}
                        <PageLink href={next} className={cn(btn, next ? idle : off)} label="Next page">
                            <span className="hidden md:inline">Next</span> <ChevronRight className="size-3.5" />
                        </PageLink>
                    </div>
                </>
            )}
        </nav>
    );
}

function PageLink({ href, className, label, current, children }: { href: string | null; className: string; label: string; current?: boolean; children: React.ReactNode }) {
    if (!href) {
        return (
            <span aria-disabled="true" aria-label={label} className={className}>
                {children}
            </span>
        );
    }
    return (
        <Link href={href} preserveScroll preserveState aria-label={label} aria-current={current ? 'page' : undefined} className={className}>
            {children}
        </Link>
    );
}
