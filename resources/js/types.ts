export type ItemSource = 'bw_products' | 'warehouse' | 'merchandise' | 'rejects';
export type RetailGroup = 'regular_product' | 'non_product';
export type OrderStatus = 'pending' | 'posted' | 'cancelled';
export type Role = 'admin' | 'store';

export interface Item {
    id: number;
    source: ItemSource;
    product_code: string;
    description: string;
    barcode: string | null;
    category: string | null;
    retail_group: RetailGroup;
}

export const retailGroupLabel: Record<RetailGroup, string> = {
    regular_product: 'Regular Product',
    non_product: 'Non-product',
};

export interface StoreRef {
    id: number;
    code: string;
    name: string;
}

export interface OrderSummary {
    id: number;
    number: string;
    ordered_by: string;
    store: StoreRef | null;
    status: OrderStatus;
    lines_count: number;
    units: number;
    bw_units: number;
    warehouse_units: number;
    merchandise_units: number;
    rejects_units: number;
    order_date: string; // YYYY-MM-DD, the day the order is for
    created_at: string;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

/** Today's submission deadline for the signed-in store (null for admins). */
export interface DeadlineInfo {
    time: string; // HH:MM
    source: 'date' | 'store' | 'default';
    at: string; // ISO timestamp of today's cutoff
    open: boolean;
    store_active: boolean;
    ordered_today: boolean;
    timezone: string;
}

/** Admins: how many active stores haven't ordered yet today. */
export interface OrderingToday {
    total: number;
    not_ordered: number;
    deadline_at: string;
    deadline_time: string;
    open: boolean;
}

export interface SharedProps {
    appName: string;
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            role: Role;
            store: { id: number; code: string; name: string; is_active: boolean } | null;
        };
    };
    flash: { success: string | null };
    deadline: DeadlineInfo | null;
    ordering_today: OrderingToday | null;
    [key: string]: unknown;
}
