// Pure occurrence-pattern math shared by the treatment follow-up package generator and the
// standalone bulk-appointment generator, so the "start + count + interval → rows" logic lives in
// one place. Kept free of Vue/form state — callers own the reactive list.
import { addDays, addMonths, addWeeks } from 'date-fns';
import type { FollowUpInterval } from '@/types/treatment';
import { combineDateTime } from '@/utils/appointmentTime';

/** One generated occurrence draft (clinic-local date + "HH:mm" time), before transform to starts_at. */
export type OccurrenceDraft = {
    date: Date | null;
    time: string;
    duration_minutes: number | null;
    appointment_type_id: number | null;
};

/** date-fns end-of-month clamping mirrors the server's Carbon addMonthNoOverflow. */
export function offsetDate(
    base: Date,
    interval: FollowUpInterval,
    step: number,
    intervalDays = 7,
): Date {
    switch (interval) {
        case 'weekly':
            return addWeeks(base, step);
        case 'biweekly':
            return addWeeks(base, step * 2);
        case 'monthly':
            return addMonths(base, step);
        case 'custom':
            return addDays(base, step * Math.max(1, intervalDays));
    }
}

/**
 * Build `count` occurrence rows from a start date/time, spaced by `interval`, each seeded with the
 * given duration/type. Returns an empty list until the start date + time form a valid slot; count
 * is clamped to 1..12 (the engine cap).
 */
export function buildOccurrences(params: {
    startDate: Date | null;
    time: string;
    count: number;
    interval: FollowUpInterval;
    /** Spacing in days when `interval` is 'custom'. */
    intervalDays?: number;
    seedDuration: number | null;
    seedTypeId: number | null;
}): OccurrenceDraft[] {
    const {
        startDate,
        time,
        count,
        interval,
        intervalDays,
        seedDuration,
        seedTypeId,
    } = params;

    if (!startDate || !combineDateTime(startDate, time)) {
        return [];
    }

    const clamped = Math.min(12, Math.max(1, count || 1));
    const rows: OccurrenceDraft[] = [];

    for (let i = 0; i < clamped; i++) {
        rows.push({
            date: offsetDate(startDate, interval, i, intervalDays),
            time,
            duration_minutes: seedDuration,
            appointment_type_id: seedTypeId,
        });
    }

    return rows;
}
