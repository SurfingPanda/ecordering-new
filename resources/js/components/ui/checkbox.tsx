import * as React from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { CheckIcon, MinusIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

function Checkbox({ className, ...props }: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
    return (
        <CheckboxPrimitive.Root
            data-slot="checkbox"
            className={cn(
                'size-5 shrink-0 rounded-[4px] border-2 border-input bg-card outline-none transition-shadow',
                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/30',
                'data-[state=checked]:border-secondary data-[state=checked]:bg-secondary data-[state=checked]:text-secondary-foreground',
                'data-[state=indeterminate]:border-secondary data-[state=indeterminate]:bg-secondary data-[state=indeterminate]:text-secondary-foreground',
                className,
            )}
            {...props}
        >
            <CheckboxPrimitive.Indicator className="flex items-center justify-center">
                {props.checked === 'indeterminate' ? <MinusIcon className="size-3.5" strokeWidth={3} /> : <CheckIcon className="size-3.5" strokeWidth={3} />}
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}

export { Checkbox };
