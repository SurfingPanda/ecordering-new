import { cn } from '@/lib/utils';
import type { OrderStatus } from '@/types';

const statusStyles: Record<OrderStatus, string> = {
    pending: 'bg-[#fff4dc] text-[#8a5200]',
    posted: 'bg-[#e3f5ea] text-[#16683a]',
    cancelled: 'bg-[#f1f1f4] text-[#5d6280] line-through decoration-[#5d6280]/50',
};

function StatusBadge({ status, className }: { status: OrderStatus; className?: string }) {
    return (
        <span className={cn('inline-block rounded px-2 py-0.5 text-xs font-semibold uppercase tracking-wide', statusStyles[status], className)}>
            {status}
        </span>
    );
}

export { StatusBadge };
