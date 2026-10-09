import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const buttonVariants = cva(
    "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-bold transition-colors disabled:pointer-events-none disabled:opacity-60 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/40",
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/90',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-secondary underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-11 px-4 py-2',
                sm: 'h-9 px-3',
                lg: 'h-12 px-6 text-base',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'button';
    return <Comp data-slot="button" className={cn(buttonVariants({ variant, size, className }))} {...props} />;
}

export { Button, buttonVariants };
