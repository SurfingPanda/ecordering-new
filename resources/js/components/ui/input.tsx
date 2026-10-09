import * as React from 'react';

import { cn } from '@/lib/utils';

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="input"
            className={cn(
                'h-11 w-full min-w-0 rounded-md border-2 border-input bg-card px-3.5 py-2 text-base outline-none transition-[color,box-shadow] placeholder:text-muted-foreground disabled:opacity-60 md:text-sm',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/20',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
