import { createI18n } from 'vue-i18n';
import { tr } from '@/locales/tr';

/**
 * Frontend i18n. Turkish is the MVP default; locale/fallback track the clinic's
 * configured locale once wired through page props. Each locale lives in its own
 * file under resources/js/locales/ — add a language there and register it here.
 */
export const i18n = createI18n({
    legacy: false,
    locale: 'tr',
    fallbackLocale: 'tr',
    messages: { tr },
});
