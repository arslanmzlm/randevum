import { createInertiaApp } from '@inertiajs/vue3';
import Aura from '@primeuix/themes/aura';
import PrimeVue from 'primevue/config';
import { i18n } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    withApp: (app) => {
        app.use(PrimeVue, {
            theme: {
                preset: Aura,
                options: {
                    // Emit PrimeVue styles into a `primevue` cascade layer placed
                    // before Tailwind's utilities so Tailwind classes can override.
                    cssLayer: {
                        name: 'primevue',
                        order: 'theme, base, primevue, utilities',
                    },
                },
            },
        });

        app.use(i18n);
    },
});
