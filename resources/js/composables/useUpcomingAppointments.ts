import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { upcoming } from '@/routes/appointments';
import type { UpcomingAppointmentDto } from '@/types/appointment';

type UpcomingResponse = { data: UpcomingAppointmentDto[] };

/**
 * Seeds its list from the `upcomingAppointments` shared prop (so the sidebar panel renders without a
 * fetch on mount) and exposes a manual `refresh()` that re-hits `GET /appointments/upcoming`.
 * Abort-and-replace shape mirrors useCalendarEvents; no polling by design.
 */
export function useUpcomingAppointments(limit?: number) {
    const page = usePage();

    const appointments = ref<UpcomingAppointmentDto[]>(
        page.props.upcomingAppointments ?? [],
    );
    const loading = ref(false);

    // The sidebar panel lives in the persistent AppLayout, so the composable instance survives
    // navigations. The shared prop is sent on every Inertia response — re-seed from it so the
    // panel stays fresh across visits without a manual refresh.
    watch(
        () => page.props.upcomingAppointments,
        (next) => {
            if (next) {
                appointments.value = next;
            }
        },
    );

    let activeRequest: AbortController | undefined;

    async function refresh(): Promise<void> {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        loading.value = true;

        try {
            const url = upcoming({
                query: limit !== undefined ? { limit } : {},
            }).url;

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (response.ok) {
                const body = (await response.json()) as UpcomingResponse;
                appointments.value = body.data;
            }
        } catch (error) {
            // A superseded refresh aborts the prior fetch — not an error worth surfacing.
            if ((error as Error).name !== 'AbortError') {
                return;
            }
        } finally {
            // Only the latest request controls the spinner; a superseded one already aborted.
            if (activeRequest === request) {
                loading.value = false;
            }
        }
    }

    return { appointments, loading, refresh };
}
