import '@/types';
import AdminLayout from '@/layouts/AdminLayout';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ResolvedComponent } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'O’CHANTIER ADMIN';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Une page = un fichier chargé à la demande.
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');
        return (await pages[`./pages/${name}.tsx`]()).default;
    },
    // Les écrans d’authentification ont leur propre mise en page ; tous les autres partagent le gabarit admin.
    layout: (name) => (name.startsWith('Auth/') ? null : AdminLayout),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: 'var(--c-accent)',
    },
});
