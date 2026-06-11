import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Money formatting bound to the active clinic's currency (shared once via `activeClinic.currency`)
 * and the active locale — the single home for rendering amounts, so no surface re-inlines an
 * `Intl.NumberFormat` or a hardcoded `₺`. The currency code drives both the symbol and the
 * locale-correct decimal/grouping. Backend amounts are decimal strings; parse defensively.
 */
export function useMoney() {
    const page = usePage();
    const { locale } = useI18n();

    // TRY is the MVP default but is never assumed — it only fills in for guest contexts with no
    // bound clinic; an authed clinic always shares its own currency.
    const currency = computed(() => page.props.activeClinic?.currency ?? 'TRY');

    const formatter = computed(
        () =>
            new Intl.NumberFormat(locale.value, {
                style: 'currency',
                currency: currency.value,
            }),
    );

    function formatMoney(value: string | number | null | undefined): string {
        const amount = typeof value === 'string' ? Number(value) : (value ?? 0);

        return formatter.value.format(Number.isFinite(amount) ? amount : 0);
    }

    return { currency, formatMoney };
}
