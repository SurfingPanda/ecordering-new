import type { ItemSource } from '@/types';

// Categorical colours: blue and orange come from the logo, teal and violet complete the set.
// All four clear the palette validator (lightness band, chroma, normal-vision separation, 3:1 contrast on white);
// charts also carry legends, labels and gaps, so colour is never the only cue.
export const catalogColor: Record<ItemSource, string> = {
    bw_products: '#3b63f0',
    warehouse: '#ff5000',
    merchandise: '#0f9d8a',
    rejects: '#8b5cf6',
};

export const catalogLabel: Record<ItemSource, string> = {
    bw_products: 'BW Products',
    warehouse: 'Warehouse',
    merchandise: 'Merchandise',
    rejects: 'Rejects',
};

/** The catalogs in the order they are shown everywhere (tabs, charts, columns). */
export const catalogs: ItemSource[] = ['bw_products', 'warehouse', 'merchandise', 'rejects'];

/** Which field of an order holds the units of each catalog. */
export const catalogUnitsKey = {
    bw_products: 'bw_units',
    warehouse: 'warehouse_units',
    merchandise: 'merchandise_units',
    rejects: 'rejects_units',
} as const;

/** One item of a catalog, e.g. "Warehouse Item". */
export const catalogSingular: Record<ItemSource, string> = {
    bw_products: 'BW Product',
    warehouse: 'Warehouse Item',
    merchandise: 'Merchandise Item',
    rejects: 'Reject Item',
};

export const nf = new Intl.NumberFormat('en-PH');

/** Round a maximum up to a "nice" axis ceiling (1, 2, 5 × 10ⁿ). */
export function niceMax(value: number): number {
    if (value <= 0) return 10;
    const pow = 10 ** Math.floor(Math.log10(value));
    const n = value / pow;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * pow;
}

export function shortDate(iso: string) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}

export function longDate(iso: string) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}
