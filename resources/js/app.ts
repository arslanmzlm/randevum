import { createInertiaApp } from '@inertiajs/vue3';
import { definePreset } from '@primeuix/themes';
import Aura from '@primeuix/themes/aura';
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import { i18n } from './i18n';
import { trLocale } from './primevue-locale';

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
    components: {
        // Aura's outlined-button hover tint ({…50}) reads almost invisible on white; bump every
        // severity one shade darker (hover 50→100, active 100→200) so the hover state is legible.
        button: {
            colorScheme: {
                light: {
                    outlined: {
                        primary: {
                            hoverBackground: '{primary.100}',
                            activeBackground: '{primary.200}',
                        },
                        secondary: {
                            hoverBackground: '{surface.100}',
                            activeBackground: '{surface.200}',
                        },
                        success: {
                            hoverBackground: '{green.100}',
                            activeBackground: '{green.200}',
                        },
                        info: {
                            hoverBackground: '{sky.100}',
                            activeBackground: '{sky.200}',
                        },
                        warn: {
                            hoverBackground: '{orange.100}',
                            activeBackground: '{orange.200}',
                        },
                        help: {
                            hoverBackground: '{purple.100}',
                            activeBackground: '{purple.200}',
                        },
                        danger: {
                            hoverBackground: '{red.100}',
                            activeBackground: '{red.200}',
                        },
                        contrast: {
                            hoverBackground: '{surface.100}',
                            activeBackground: '{surface.200}',
                        },
                        plain: {
                            hoverBackground: '{surface.100}',
                            activeBackground: '{surface.200}',
                        },
                    },
                },
            },
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
            locale: trLocale,
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

        app.use(ToastService);
        app.use(ConfirmationService);
        app.use(i18n);
    },
});
