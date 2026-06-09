import { ref, watch } from 'vue';
import { availability } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
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

    let debounceTimer: ReturnType<typeof setTimeout> | undefined;
    let activeRequest: AbortController | undefined;

    async function run(current: AvailabilityCheckParams): Promise<void> {
        // Cancel any in-flight probe so a slow earlier response can't clobber a newer slot.
        activeRequest?.abort();
        activeRequest = new AbortController();

        try {
            const response = await fetch(
                availability({ query: { ...current } }).url,
                {
                    headers: { Accept: 'application/json' },
                    signal: activeRequest.signal,
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
    }

    watch(
        params,
        (current) => {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }

            if (!current) {
                activeRequest?.abort();
                state.value = 'idle';
                reason.value = null;

                return;
            }

            state.value = 'checking';
            debounceTimer = setTimeout(() => void run(current), DEBOUNCE_MS);
        },
        { deep: true },
    );

    return { state, reason };
}
