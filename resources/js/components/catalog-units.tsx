import { nf } from '@/lib/charts';
import { cn } from '@/lib/utils';

/** Units of one catalog in an order; a dash when that catalog isn't part of the order. */
export default function CatalogUnits({ value }: { value: number }) {
    return <span className={cn('tabular-nums', value > 0 ? 'font-semibold' : 'text-muted-foreground/60')}>{value > 0 ? nf.format(value) : '–'}</span>;
}