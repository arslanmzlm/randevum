import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FormatRangeOptions } from '@/utils/datetime';
import {
    daysSince,
    formatDate,
    formatDateOnly,
    formatDateTime,
    formatFullDate,
    formatLongDate,
    formatMonthYear,
    formatRange,
    formatTime,
    formatWeekdayShort,
    isPast,
    parseUtc,
} from '@/utils/datetime';

/**
 * Binds the clinic timezone (shared once via `activeClinic.timezone`) + the active locale to the
 * pure datetime helpers, so components call `formatDate(iso)` without threading tz/locale through.
 * Pass `tzOverride` only for a non-active-clinic context; otherwise the global share is the single
 * source. Falls back to the browser-resolved zone for guest contexts where no clinic is bound.
 */
export function useDateTime(tzOverride?: string) {
    const page = usePage();
    const { locale } = useI18n();

    const timeZone = computed(
        () =>
            tzOverride ??
            page.props.activeClinic?.timezone ??
            Intl.DateTimeFormat().resolvedOptions().timeZone,
    );

    const ctx = computed(() => ({
        timeZone: timeZone.value,
        locale: locale.value,
    }));

    return {
        timeZone,
        formatDate: (value: string | Date) => formatDate(value, ctx.value),
        formatTime: (value: string | Date) => formatTime(value, ctx.value),
        formatDateTime: (value: string | Date) =>
            formatDateTime(value, ctx.value),
        formatMonthYear: (value: string | Date) =>
            formatMonthYear(value, locale.value),
        formatWeekdayShort: (value: string | Date) =>
            formatWeekdayShort(value, locale.value),
        formatFullDate: (value: string | Date) =>
            formatFullDate(value, locale.value),
        formatLongDate: (value: string | Date) =>
            formatLongDate(value, locale.value),
        formatRange: (
            start: string | Date,
            end: string | Date,
            options?: FormatRangeOptions,
        ) => formatRange(start, end, ctx.value, options),
        parseUtc: (value: string | Date) => parseUtc(value, timeZone.value),
        formatDateOnly: (value: string | Date) =>
            formatDateOnly(value, locale.value),
        isPast,
        daysSince,
    };
}
