import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCan } from '@/composables/useCan';
import { start } from '@/routes/treatments';
import type { AppointmentStatus } from '@/types/enums';

/** Minimal shape the treatment gate needs — satisfied by a list row and a calendar event alike. */
export type TreatableAppointment = {
    /** The appointment id (treatment is started from the appointment). */
    id: number;
    doctor_id: number;
    status: AppointmentStatus;
    /** The 1:1 treatment id when one already exists (Draft to resume). */
    treatment_id: number | null;
};

/** Statuses a treatment can be started/resumed from (mirrors TreatmentService::start guards). */
const STARTABLE: AppointmentStatus[] = ['confirmed', 'rescheduled', 'arrived'];

/**
 * Shared "start / resume treatment" action carrying the exact permission + status + doctor-ownership
 * gating the server enforces, so the appointment list row menu and the calendar popover never drift.
 * Posting to `treatments.start` is idempotent — it creates the Draft or redirects to the existing
 * one — so the same call handles both "Tedaviye başla" and "Tedaviye devam et".
 */
export function useTreatmentActions(ownDoctorId: number | null) {
    const { can } = useCan();

    const canCreate = computed(() => can('treatments.create'));
    const canViewAllAppointments = computed(() => can('appointments.viewAll'));

    function canStartTreatment(row: TreatableAppointment): boolean {
        const ownsOrViewsAll =
            canViewAllAppointments.value || row.doctor_id === ownDoctorId;

        return (
            canCreate.value && ownsOrViewsAll && STARTABLE.includes(row.status)
        );
    }

    /** True when a Draft already exists → label the action "resume" instead of "start". */
    function isResume(row: TreatableAppointment): boolean {
        return row.treatment_id !== null;
    }

    function startTreatment(row: TreatableAppointment): void {
        router.post(start(row.id).url);
    }

    return { canStartTreatment, isResume, startTreatment };
}

export type TreatmentActions = ReturnType<typeof useTreatmentActions>;
