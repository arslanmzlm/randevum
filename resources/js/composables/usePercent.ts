import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Percent formatting bound to the active locale — the single home for rendering rates, mirroring
 * useMoney() so no surface re-inlines its own `Intl.NumberFormat({ style: 'percent' })`. Values
 * are 0–100 (not 0–1 fractions); Intl takes a fraction and places the sign per locale (tr: %12,5).
 */
export function usePercent() {
    const { locale } = useI18n();

    const formatter = computed(
        () =>
            new Intl.NumberFormat(locale.value, {
                style: 'percent',
                maximumFractionDigits: 1,
            }),
    );

    function formatPercent(value: number | string | null | undefined): string {
        const amount = typeof value === 'string' ? Number(value) : (value ?? 0);

        return formatter.value.format(
            (Number.isFinite(amount) ? amount : 0) / 100,
        );
    }

    return { formatPercent };
}
