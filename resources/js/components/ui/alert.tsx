import * as React from 'react';

import { cn } from '@/lib/utils';

function Alert({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            role="alert"
            data-slot="alert"
            className={cn(
                'rounded-md border-l-4 border-primary bg-[#ffe9e2] px-3 py-2.5 text-sm text-[#9c2a00]',
                className,
            )}
            {...props}
        />
    );
}

export { Alert };
