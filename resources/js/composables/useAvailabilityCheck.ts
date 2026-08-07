import { ref } from 'vue';
import { availability } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import { useDebouncedFetch } from '@/composables/useDebouncedFetch';
import type {
    AvailabilityCheckResponse,
    AvailabilityReason,
} from '@/types/appointment';

export type AvailabilityState =
    | 'idle'
    | 'checking'
    | 'available'
    | 'unavailable';

/** Query the `appointments.availability` pre-check needs; null = not enough input yet. */
export type AvailabilityCheckParams = {
    doctor_id: number;
    starts_at: string;
    duration_minutes: number | null;
    service_id: number | null;
    /** Type default duration applies when no service/duration is given (service → type → clinic). */
    appointment_type_id?: number | null;
    is_walk_in: boolean;
    /** When rescheduling, the row's own id so the probe ignores its current slot. */
    exclude_appointment_id?: number;
};

const DEBOUNCE_MS = 350;

/**
 * Debounced live availability pre-check for the create-appointment form. Pass a getter that
 * returns the current params (or null while doctor/date/time are incomplete); the probe re-runs
 * whenever those change. Advisory only — the server POST stays the real gate. Reusable by the
 * future B2C online-booking flow.
 */
export function useAvailabilityCheck(
    params: () => AvailabilityCheckParams | null,
) {
    const state = ref<AvailabilityState>('idle');
    const reason = ref<AvailabilityReason | null>(null);

    useDebouncedFetch({
        params,
        debounceMs: DEBOUNCE_MS,
        onPending: () => {
            state.value = 'checking';
        },
        onIdle: () => {
            state.value = 'idle';
            reason.value = null;
        },
        async run(current, signal) {
            try {
                const response = await fetch(
                    availability({ query: { ...current } }).url,
                    {
                        headers: { Accept: 'application/json' },
                        signal,
                    },
                );

                if (!response.ok) {
                    state.value = 'idle';
                    reason.value = null;

                    return;
                }

                const body = (await response.json()) as AvailabilityCheckResponse;
                reason.value = body.reason;
                state.value = body.available ? 'available' : 'unavailable';
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    state.value = 'idle';
                    reason.value = null;
                }
            }
        },
    });

    return { state, reason };
}
