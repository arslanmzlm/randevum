import { watchDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { events as calendarEvents } from '@/routes/calendar';
import type {
    CalendarEventDto,
    CalendarEventsResponse,
    CalendarExceptionDto,
} from '@/types/calendar';
import type { AppointmentStatus } from '@/types/enums';

export type CalendarEventsState = 'idle' | 'loading' | 'loaded' | 'error';

/** Clinic-local Y-m-d range + filters the calendar query needs; null = range not resolved yet. */
export type CalendarEventsParams = {
    start: string;
    end: string;
    doctorId: number | null;
    statuses: AppointmentStatus[];
};

const DEBOUNCE_MS = 250;

/**
 * Debounced fetch of calendar events for the visible range + filters. Pass a getter returning the
 * params (or null while the range is unresolved); re-fires on view/date/filter change and ignores
 * stale responses. Mirrors useDaySchedule's abort-and-debounce shape.
 */
export function useCalendarEvents(params: () => CalendarEventsParams | null) {
    const state = ref<CalendarEventsState>('idle');
    const data = ref<CalendarEventDto[]>([]);
    const exceptions = ref<CalendarExceptionDto[]>([]);

    let activeRequest: AbortController | undefined;

    async function run(current: CalendarEventsParams): Promise<void> {
        activeRequest?.abort();
        activeRequest = new AbortController();

        try {
            const url = calendarEvents({
                query: {
                    start: current.start,
                    end: current.end,
                    ...(current.doctorId !== null
                        ? { doctor_id: current.doctorId }
                        : {}),
                    statuses: current.statuses,
                },
            }).url;

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal,
            });

            if (!response.ok) {
                data.value = [];
                exceptions.value = [];
                state.value = 'error';

                return;
            }

            const body = (await response.json()) as CalendarEventsResponse;
            data.value = body.data;
            exceptions.value = body.exceptions;
            state.value = 'loaded';
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                data.value = [];
                exceptions.value = [];
                state.value = 'error';
            }
        }
    }

    // Reflect pending/idle the moment params change, before the debounced fetch fires, so the
    // spinner reacts instantly to navigation/filter changes.
    watch(
        params,
        (current) => {
            if (!current) {
                activeRequest?.abort();
                data.value = [];
                exceptions.value = [];
                state.value = 'idle';

                return;
            }

            state.value = 'loading';
        },
        { deep: true, immediate: true },
    );

    watchDebounced(
        params,
        (current) => {
            if (current) {
                void run(current);
            }
        },
        { debounce: DEBOUNCE_MS, deep: true, immediate: true },
    );

    return { state, data, exceptions };
}
