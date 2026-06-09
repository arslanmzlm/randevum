import { watchDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { bulkCancelPreview } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import type { BulkCancelPreviewRow } from '@/types/appointment';

export type BulkCancelPreviewState = 'idle' | 'loading' | 'loaded' | 'error';

/** Clinic-local Y-m-d range + optional doctor the preview probe needs; null until both dates set. */
export type BulkCancelPreviewParams = {
    start_date: string;
    end_date: string;
    doctor_id: number | null;
};

const DEBOUNCE_MS = 300;

/**
 * Debounced, read-only fetch of the appointments a bulk-cancel WOULD affect for the chosen
 * range/doctor. Pass a getter returning the params (or null while the range is unresolved);
 * re-fires on date/doctor change and ignores stale responses. Mirrors useCalendarEvents'
 * abort-and-debounce shape.
 */
export function useBulkCancelPreview(
    params: () => BulkCancelPreviewParams | null,
) {
    const state = ref<BulkCancelPreviewState>('idle');
    const count = ref(0);
    const rows = ref<BulkCancelPreviewRow[]>([]);

    let activeRequest: AbortController | undefined;

    async function run(current: BulkCancelPreviewParams): Promise<void> {
        activeRequest?.abort();
        activeRequest = new AbortController();

        try {
            const url = bulkCancelPreview({
                query: {
                    start_date: current.start_date,
                    end_date: current.end_date,
                    ...(current.doctor_id !== null
                        ? { doctor_id: current.doctor_id }
                        : {}),
                },
            }).url;

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: activeRequest.signal,
            });

            if (!response.ok) {
                count.value = 0;
                rows.value = [];
                state.value = 'error';

                return;
            }

            const body = (await response.json()) as {
                count: number;
                appointments: BulkCancelPreviewRow[];
            };
            count.value = body.count;
            rows.value = body.appointments;
            state.value = 'loaded';
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                count.value = 0;
                rows.value = [];
                state.value = 'error';
            }
        }
    }

    // Reflect pending/idle the moment params change, before the debounced fetch fires, so the
    // preview reacts instantly to the date/doctor controls.
    watch(
        params,
        (current) => {
            if (!current) {
                activeRequest?.abort();
                count.value = 0;
                rows.value = [];
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

    return { state, count, rows };
}
