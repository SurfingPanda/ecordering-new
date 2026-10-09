import { useEffect, useRef, useState } from 'react';

import { nf } from '@/lib/charts';

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Counts from the previously shown value to `value` (e.g. when the report period changes). */
export default function AnimatedNumber({ value, duration = 700 }: { value: number; duration?: number }) {
    const [shown, setShown] = useState(0);
    const from = useRef(0);

    useEffect(() => {
        if (prefersReducedMotion()) {
            from.current = value;
            setShown(value);
            return;
        }

        const start = performance.now();
        const origin = from.current;
        let frame = 0;

        const tick = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
            const current = Math.round(origin + (value - origin) * eased);
            from.current = current;
            setShown(current);
            if (t < 1) frame = requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [value, duration]);

    return <>{nf.format(shown)}</>;
}
