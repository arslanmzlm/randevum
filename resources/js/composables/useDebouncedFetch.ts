import { onUnmounted, watch } from 'vue';

export type UseDebouncedFetchOptions<TParams> = {
    /** Getter for the current params; null = not enough input to fetch yet. */
    params: () => TParams | null;
    /** Debounce delay (ms) applied to every params change, including the initial one when
     *  `immediate` is set — no caller skips the delay for its first fetch. */
    debounceMs: number;
    /**
     * Runs one fetch attempt. Receives an AbortSignal that this composable aborts before starting
     * the next attempt and on unmount — pass it straight to `fetch()`. Must catch its own errors
     * (including AbortError, which fires when a newer params change supersedes this attempt);
     * this composable does not touch the caller's state refs.
     */
    run: (current: TParams, signal: AbortSignal) => Promise<void>;
    /** Fires synchronously the moment params change to a non-null value, before the debounce
     *  delay — e.g. to flip a `state` ref to a "pending/loading" value immediately. */
    onPending?: (current: TParams) => void;
    /** Fires synchronously when params become null, after aborting any in-flight/pending
     *  request — use it to reset state/data refs to their idle shape. */
    onIdle?: () => void;
    /** Mirrors Vue `watch`'s `immediate`: run the pending→debounced-fetch cycle for the params
     *  present at setup instead of waiting for the first change (e.g. an edit form that mounts
     *  with its fields already filled). */
    immediate?: boolean;
};

/**
 * Shared debounce + abort-fetch engine behind the appointment/calendar "live preview" composables
 * (useAvailabilityCheck, useDaySchedule, useBulkCancelPreview, useCalendarEvents). Centralizes the
 * AbortController lifecycle (abort the previous attempt before starting the next, and on unmount)
 * so callers only supply the fetch itself plus the pending/idle state transitions.
 */
export function useDebouncedFetch<TParams>(
    options: UseDebouncedFetchOptions<TParams>,
): { trigger: (current: TParams) => void } {
    const { params, debounceMs, run, onPending, onIdle, immediate } = options;

    let debounceTimer: ReturnType<typeof setTimeout> | undefined;
    let activeRequest: AbortController | undefined;

    function clearPending(): void {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = undefined;
        }
    }

    /** Aborts any in-flight attempt and starts a new one immediately (bypassing the debounce) —
     *  used internally once the timer elapses, and exposed for callers that need a manual
     *  re-fetch of the last params (e.g. after a mutation invalidates fetched data). */
    function trigger(current: TParams): void {
        activeRequest?.abort();
        activeRequest = new AbortController();
        void run(current, activeRequest.signal);
    }

    watch(
        params,
        (current) => {
            clearPending();

            if (!current) {
                activeRequest?.abort();
                onIdle?.();

                return;
            }

            onPending?.(current);
            debounceTimer = setTimeout(() => trigger(current), debounceMs);
        },
        { deep: true, immediate },
    );

    onUnmounted(() => {
        clearPending();
        activeRequest?.abort();
    });

    return { trigger };
}
