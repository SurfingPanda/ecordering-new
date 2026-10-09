import { catalogColor, catalogLabel, catalogs, nf } from '@/lib/charts';
import type { ItemSource } from '@/types';

type Split = Record<ItemSource, { units: number; items: number }>;

/** Part-to-whole as one stacked bar (not a pie): units per catalog. */
export default function ShareBar({ split, animKey }: { split: Split; animKey?: string | number }) {
    const keys: ItemSource[] = catalogs;
    const total = keys.reduce((s, k) => s + (split[k]?.units ?? 0), 0);

    if (total === 0) return <p className="py-10 text-center text-sm text-muted-foreground">No orders in this period.</p>;

    return (
        <div>
            <div key={animKey} className="anim-grow-x flex h-3 gap-0.5" role="img" aria-label={keys.map((k) => `${catalogLabel[k]} ${Math.round((split[k].units / total) * 100)}%`).join(', ')}>
                {keys.map(
                    (k) =>
                        split[k].units > 0 && (
                            <div
                                key={k}
                                title={`${catalogLabel[k]}: ${nf.format(split[k].units)} units`}
                                className="first:rounded-l-[4px] last:rounded-r-[4px]"
                                style={{ width: `${(split[k].units / total) * 100}%`, background: catalogColor[k] }}
                            />
                        ),
                )}
            </div>

            <dl className="mt-5 space-y-4">
                {keys.map((k) => {
                    const pct = (split[k].units / total) * 100;
                    return (
                        <div key={k} className="flex items-start gap-3">
                            <span className="mt-1 size-2.5 shrink-0 rounded-[3px]" style={{ background: catalogColor[k] }} />
                            <div className="flex-1">
                                <dt className="text-[13px] font-medium">{catalogLabel[k]}</dt>
                                <dd className="text-xs text-muted-foreground">
                                    {split[k].items} {split[k].items === 1 ? 'item' : 'items'} ordered
                                </dd>
                            </div>
                            <dd className="text-right">
                                <p className="text-lg font-semibold leading-tight tabular-nums">{nf.format(split[k].units)}</p>
                                <p className="text-xs text-muted-foreground">{pct < 1 && pct > 0 ? '<1' : Math.round(pct)}% of units</p>
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}
