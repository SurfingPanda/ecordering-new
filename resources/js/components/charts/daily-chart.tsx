import { useState } from 'react';

import { catalogColor, catalogLabel, catalogs, longDate, nf, niceMax, shortDate } from '@/lib/charts';
import { cn } from '@/lib/utils';
import type { ItemSource } from '@/types';

export type DailyPoint = { date: string } & Record<ItemSource, number>;

const sum = (d: DailyPoint) => catalogs.reduce((s, k) => s + (d[k] ?? 0), 0);

/** Units ordered per day, stacked by catalog. Plain HTML so hover, focus and a table view come for free. */
export default function DailyChart({ data, animKey }: { data: DailyPoint[]; animKey?: string | number }) {
    const [hover, setHover] = useState<number | null>(null);
    const [table, setTable] = useState(false);

    const totals = data.map(sum);
    const max = niceMax(Math.max(0, ...totals));
    const step = Math.ceil(data.length / 6);
    const pct = (v: number) => `${(v / max) * 100}%`;
    const point = hover !== null ? data[hover] : null;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <Legend />
                <button
                    type="button"
                    onClick={() => setTable((t) => !t)}
                    aria-pressed={table}
                    className="rounded border border-border px-2 py-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    {table ? 'View chart' : 'View as table'}
                </button>
            </div>

            {table ? (
                <div className="no-scrollbar max-h-64 overflow-auto rounded-md border border-border">
                    <table className="w-full border-collapse text-[13px]">
                        <thead className="sticky top-0 bg-[#f2f4f8] text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Date</th>
                                {catalogs.map((k) => (
                                    <th key={k} className="px-3 py-2 text-right font-semibold">
                                        {catalogLabel[k]}
                                    </th>
                                ))}
                                <th className="px-3 py-2 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...data].reverse().map((d) => (
                                <tr key={d.date} className="border-t border-border/70">
                                    <td className="px-3 py-1.5">{longDate(d.date)}</td>
                                    {catalogs.map((k) => (
                                        <td key={k} className="px-3 py-1.5 text-right tabular-nums">
                                            {nf.format(d[k] ?? 0)}
                                        </td>
                                    ))}
                                    <td className="px-3 py-1.5 text-right font-medium tabular-nums">{nf.format(sum(d))}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="flex gap-2">
                    {/* y axis */}
                    <div className="relative h-56 w-9 shrink-0 text-right text-[11px] text-muted-foreground">
                        {[1, 0.5, 0].map((f) => (
                            <span key={f} className="absolute right-0 -translate-y-1/2 tabular-nums" style={{ top: `${(1 - f) * 100}%` }}>
                                {nf.format(max * f)}
                            </span>
                        ))}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="relative h-56" onMouseLeave={() => setHover(null)}>
                            {[0, 0.5, 1].map((f) => (
                                <div key={f} className="absolute inset-x-0 border-t border-[#e6e8f0]" style={{ top: `${(1 - f) * 100}%` }} />
                            ))}

                            <div key={animKey} className="absolute inset-0 flex items-end gap-[3px]">
                                {data.map((d, i) => {
                                    const total = totals[i];
                                    // the last catalog sits on top; 2px gap between the fills, 4px-rounded data end
                                    const present = catalogs.filter((k) => (d[k] ?? 0) > 0);
                                    return (
                                        <div
                                            key={d.date}
                                            tabIndex={0}
                                            role="img"
                                            aria-label={`${longDate(d.date)}: ${total} units (${catalogs.map((k) => `${catalogLabel[k]} ${d[k] ?? 0}`).join(', ')})`}
                                            onMouseEnter={() => setHover(i)}
                                            onFocus={() => setHover(i)}
                                            onBlur={() => setHover(null)}
                                            className={cn('relative flex h-full min-w-0 flex-1 flex-col justify-end outline-none', hover === i && 'bg-[#3b63f0]/[0.07]')}
                                        >
                                            <div className="anim-grow-y flex h-full flex-col-reverse justify-start" style={{ ['--delay' as string]: `${Math.round((i / data.length) * 420)}ms` }}>
                                                {present.map((k, n) => (
                                                    <div
                                                        key={k}
                                                        className={cn(n === present.length - 1 && 'rounded-t-[3px]')}
                                                        style={{ height: pct(d[k]), background: catalogColor[k], marginTop: n === present.length - 1 ? 0 : 0, marginBottom: n < present.length - 1 ? 2 : 0 }}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {point && hover !== null && (
                                <div
                                    role="status"
                                    className="pointer-events-none absolute z-10 w-44 rounded-md border border-border bg-white p-2.5 text-xs shadow-lg"
                                    style={{
                                        left: `${((hover + 0.5) / data.length) * 100}%`,
                                        top: 0,
                                        transform: `translateX(${hover > data.length * 0.6 ? 'calc(-100% - 10px)' : '10px'})`,
                                    }}
                                >
                                    <p className="mb-1.5 font-semibold">{longDate(point.date)}</p>
                                    {catalogs.map((k) => (
                                        <TipRow key={k} color={catalogColor[k]} label={catalogLabel[k]} value={point[k] ?? 0} />
                                    ))}
                                    <div className="mt-1.5 flex justify-between border-t border-border pt-1.5 font-semibold">
                                        <span>Total units</span>
                                        <span className="tabular-nums">{nf.format(sum(point))}</span>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* x axis */}
                        <div className="mt-1.5 flex gap-[3px] text-[11px] text-muted-foreground">
                            {data.map((d, i) => (
                                <div key={d.date} className="relative min-w-0 flex-1">
                                    {(i % step === 0 || i === data.length - 1) && (i % step === 0 || data.length - 1 - i >= step / 2) && (
                                        <span className="absolute left-1/2 -translate-x-1/2 whitespace-nowrap">{shortDate(d.date)}</span>
                                    )}
                                </div>
                            ))}
                        </div>
                        <div className="h-4" />
                    </div>
                </div>
            )}
        </div>
    );
}

function Legend() {
    return (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            {catalogs.map((k) => (
                <li key={k} className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-[3px]" style={{ background: catalogColor[k] }} />
                    {catalogLabel[k]}
                </li>
            ))}
        </ul>
    );
}

function TipRow({ color, label, value }: { color: string; label: string; value: number }) {
    return (
        <div className="flex items-center justify-between gap-2 py-0.5">
            <span className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-[3px]" style={{ background: color }} />
                {label}
            </span>
            <span className="tabular-nums">{nf.format(value)}</span>
        </div>
    );
}
