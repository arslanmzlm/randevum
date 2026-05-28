import { createInertiaApp } from '@inertiajs/vue3';
import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';
import PrimeVue from 'primevue/config';
import { i18n } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/**
 * Brand-tuned Aura preset — maps #00be99 (brand teal) as the primary colour
 * so all PrimeVue components (Button, InputText focus ring, etc.) use it.
 */
const AppPreset = definePreset(Aura, {
    semantic: {
        primary: {
            50: '#e6faf5',
            100: '#b3f0e1',
            200: '#80e5cd',
            300: '#4ddab9',
            400: '#26d1ab',
            500: '#00be99',
            600: '#00a884',
            700: '#008c6d',
            800: '#007059',
            900: '#005443',
            950: '#002f25',
        },
        formField: {
            borderRadius: '8px',
            borderColor: '#dcdcdc',
            paddingX: '1rem',
        },
    },
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#00be99',
    },
    withApp: (app) => {
        app.use(PrimeVue, {
            theme: {
                preset: AppPreset,
                options: {
                    // Gate dark tokens on a `.dark` class, not the default 'system'
                    // (which auto-darkens components on a dark-OS). No dark mode yet.
                    darkModeSelector: '.dark',
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
