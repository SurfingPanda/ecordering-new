import { nf } from '@/lib/charts';

export interface BarRow {
    key: string;
    label: string;
    sublabel?: string | null;
    value: number;
    color: string;
    note?: string; // shown in the hover title
}

/** Ranked horizontal bars: label and value in text ink, a thin colored bar carries identity. */
export default function BarList({
    rows,
    unit = 'units',
    empty = 'No orders in this period.',
    animKey,
}: {
    rows: BarRow[];
    unit?: string;
    empty?: string;
    animKey?: string | number;
}) {
    if (rows.length === 0) return <p className="py-10 text-center text-sm text-muted-foreground">{empty}</p>;

    const max = Math.max(...rows.map((r) => r.value));

    return (
        <ol key={animKey} className="space-y-3">
            {rows.map((r, i) => (
                <li key={r.key} title={`${r.label}: ${nf.format(r.value)} ${unit}${r.note ? ` · ${r.note}` : ''}`}>
                    <div className="flex items-baseline gap-2">
                        <span className="w-4 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{i + 1}</span>
                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium">{r.label}</span>
                        <span className="text-[13px] font-semibold tabular-nums">{nf.format(r.value)}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2 pl-6">
                        <div className="h-1.5 flex-1">
                            <div
                                className="anim-grow-x h-full rounded-r-[4px]"
                                style={{ width: `${Math.max(2, (r.value / max) * 100)}%`, background: r.color, ['--delay' as string]: `${i * 70}ms` }}
                            />
                        </div>
                    </div>
                    {r.sublabel && <p className="mt-0.5 truncate pl-6 text-xs text-muted-foreground">{r.sublabel}</p>}
                </li>
            ))}
        </ol>
    );
}
