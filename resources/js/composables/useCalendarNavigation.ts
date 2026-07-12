import {
    addDays,
    addMonths,
    addWeeks,
    endOfDay,
    endOfMonth,
    endOfWeek,
    startOfDay,
    startOfMonth,
    startOfWeek,
    subDays,
    subMonths,
    subWeeks,
} from 'date-fns';
import { computed, ref } from 'vue';
import { useDateTime } from '@/composables/useDateTime';
import type { CalendarView } from '@/types/calendar';

/**
 * The calendar's pure navigation + range math: which view/date is active, the fetch range it
 * implies, the header title, and prev/next/today stepping. Deliberately holds NO filter/colour/
 * event/popover concerns — those stay in the page (it owns orchestration). `viewDate`/`activeView`
 * are returned as mutable refs so the page can also jump into a day (month-cell / summary click).
 */
export function useCalendarNavigation(defaultView: CalendarView) {
    const { formatMonthYear, formatFullDate, formatLongDate } = useDateTime();

    const activeView = ref<CalendarView>(defaultView);
    const viewDate = ref<Date>(new Date());

    // The fetch range derived from view + date, so events refetch on every navigation. Month fetches
    // the full visible grid (≤6 weeks).
    const range = computed<{ start: Date; end: Date }>(() => {
        const d = viewDate.value;

        if (activeView.value === 'month') {
            return {
                start: startOfWeek(startOfMonth(d), { weekStartsOn: 1 }),
                end: endOfWeek(endOfMonth(d), { weekStartsOn: 1 }),
            };
        }

        if (activeView.value === 'day') {
            return { start: startOfDay(d), end: endOfDay(d) };
        }

        return {
            start: startOfWeek(d, { weekStartsOn: 1 }),
            end: endOfWeek(d, { weekStartsOn: 1 }),
        };
    });

    const currentTitle = computed(() => {
        if (activeView.value === 'month') {
            return formatMonthYear(viewDate.value);
        }

        if (activeView.value === 'day') {
            return formatFullDate(viewDate.value);
        }

        const weekStart = startOfWeek(viewDate.value, { weekStartsOn: 1 });
        const weekEnd = endOfWeek(viewDate.value, { weekStartsOn: 1 });
        const sameMonth =
            weekStart.getMonth() === weekEnd.getMonth() &&
            weekStart.getFullYear() === weekEnd.getFullYear();

        // Long form, e.g. "1 – 7 Haziran 2026" (same month) or "28 Haziran – 4 Temmuz 2026".
        return sameMonth
            ? `${weekStart.getDate()} – ${formatLongDate(weekEnd)}`
            : `${formatLongDate(weekStart)} – ${formatLongDate(weekEnd)}`;
    });

    function goPrev(): void {
        const shift = { month: subMonths, week: subWeeks, day: subDays }[
            activeView.value
        ];
        viewDate.value = shift(viewDate.value, 1);
    }

    function goNext(): void {
        const shift = { month: addMonths, week: addWeeks, day: addDays }[
            activeView.value
        ];
        viewDate.value = shift(viewDate.value, 1);
    }

    function goToday(): void {
        viewDate.value = new Date();
    }

    return {
        activeView,
        viewDate,
        range,
        currentTitle,
        goPrev,
        goNext,
        goToday,
    };
}
