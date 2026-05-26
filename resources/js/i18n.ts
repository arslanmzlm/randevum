import { createI18n } from 'vue-i18n';

/**
 * Frontend i18n. Mirrors the backend lang files; Turkish is the MVP default,
 * but locale/fallback are never hardcoded assumptions — they track the
 * clinic's configured locale once that is wired through page props.
 */
export const i18n = createI18n({
    legacy: false,
    locale: 'tr',
    fallbackLocale: 'tr',
    messages: {
        tr: {},
    },
});
