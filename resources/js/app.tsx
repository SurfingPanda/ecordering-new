import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => (title ? `${title} · BW Superbakeshop` : 'BW Superbakeshop'),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        return pages[`./pages/${name}.tsx`] as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
