import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    title: (title) => (title ? `${title} · BW Superbakeshop` : 'BW Superbakeshop'),
    resolve: async (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx');
        return (await pages[`./pages/${name}.tsx`]()) as never;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});

if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
