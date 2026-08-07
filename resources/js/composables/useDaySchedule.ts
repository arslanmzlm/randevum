import { ref } from 'vue';
import { daySchedule } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import { useDebouncedFetch } from '@/composables/useDebouncedFetch';
import type { DayScheduleEntry } from '@/types/appointment';

export type DayScheduleState = 'idle' | 'loading' | 'loaded';

/** Doctor + day the panel needs; null = no doctor/date picked yet. */
export type DayScheduleParams = {
    doctor_id: number;
    date: string;
};

const DEBOUNCE_MS = 350;

/**
 * Debounced fetch of the chosen doctor's appointments for the chosen day (advisory load context
 * on the create form). Pass a getter returning the params (or null when incomplete); re-fires on
 * doctor/date change and ignores stale responses. Reusable by the future B2C booking flow.
 */
export function useDaySchedule(params: () => DayScheduleParams | null) {
    const state = ref<DayScheduleState>('idle');
    const entries = ref<DayScheduleEntry[]>([]);

    useDebouncedFetch({
        params,
        debounceMs: DEBOUNCE_MS,
        onPending: () => {
            state.value = 'loading';
        },
        onIdle: () => {
            entries.value = [];
            state.value = 'idle';
        },
        // immediate: the edit form mounts with doctor + date already filled, so the panel must
        // fetch on load — not only when the staff member changes a field.
        immediate: true,
        async run(current, signal) {
            try {
                const response = await fetch(
                    daySchedule({ query: { ...current } }).url,
                    {
                        headers: { Accept: 'application/json' },
                        signal,
                    },
                );

                if (!response.ok) {
                    entries.value = [];
                    state.value = 'loaded';

                    return;
                }

                const body = (await response.json()) as {
                    data: DayScheduleEntry[];
                };
                entries.value = body.data;
                state.value = 'loaded';
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    entries.value = [];
                    state.value = 'loaded';
                }
            }
        },
    });

    return { state, entries };
}
