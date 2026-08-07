import { ref } from 'vue';
import { useDebouncedFetch } from '@/composables/useDebouncedFetch';
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
    /** Selected doctor ids; empty = every doctor the viewer may see. */
    doctorIds: number[];
    /** Selected branch clinic ids; empty = the active clinic only. */
    clinicIds: number[];
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

    let lastParams: CalendarEventsParams | null = null;

    const { trigger } = useDebouncedFetch({
        params,
        debounceMs: DEBOUNCE_MS,
        // Reflect pending/idle the moment params change, before the debounced fetch fires, so the
        // spinner reacts instantly to navigation/filter changes.
        onPending: () => {
            state.value = 'loading';
        },
        onIdle: () => {
            data.value = [];
            exceptions.value = [];
            state.value = 'idle';
        },
        immediate: true,
        async run(current, signal) {
            lastParams = current;

            try {
                const url = calendarEvents({
                    query: {
                        start: current.start,
                        end: current.end,
                        ...(current.doctorIds.length
                            ? { doctor_id: current.doctorIds.join(',') }
                            : {}),
                        ...(current.clinicIds.length
                            ? { clinic_id: current.clinicIds }
                            : {}),
                        statuses: current.statuses,
                    },
                }).url;

                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal,
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
        },
    });

    // Re-fetch the current range without waiting for a param change — used after a mutation (cancel/
    // delete from the event popover) since calendar events come from JSON, not reloaded Inertia props.
    function refresh(): void {
        if (lastParams) {
            trigger(lastParams);
        }
    }

    return { state, data, exceptions, refresh };
}
