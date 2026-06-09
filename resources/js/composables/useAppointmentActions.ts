import { router } from '@inertiajs/vue3';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { cancel, destroy, edit } from '@/routes/appointments';
import type { AppointmentStatus } from '@/types/enums';

/** Minimal shape the lifecycle gates need — satisfied by both a list row and a calendar event. */
export type ActionableAppointment = {
    id: number;
    doctor_id: number;
    status: AppointmentStatus;
    /** ISO 8601 UTC start instant. */
    starts_at: string;
};

/**
 * Shared appointment lifecycle actions (reschedule / cancel / delete) carrying the exact
 * permission + status + doctor-ownership gating the server enforces — so the list row menu and
 * the calendar event popover never drift. Pair with one `<AppointmentCancelDialog
 * v-model="cancelReason" />` mounted on the host page (the optional-reason dialog group).
 */
export type AppointmentActionsOptions = {
    /** Called after a successful cancel/delete — the calendar uses it to refetch its JSON events
     *  (the list relies on Inertia reloading its prop, so it needs nothing here). */
    onSuccess?: () => void;
};

export function useAppointmentActions(
    ownDoctorId: number | null,
    options: AppointmentActionsOptions = {},
) {
    const { t } = useI18n();
    const { can } = useCan();
    const { isPast } = useDateTime();
    const confirm = useConfirm();

    const canViewAll = computed(() => can('appointments.viewAll'));
    const cancelReason = ref('');

    // Mirror the server-side ownership branch: act on any appointment with viewAll, else only own.
    function canAct(row: ActionableAppointment): boolean {
        return canViewAll.value || row.doctor_id === ownDoctorId;
    }

    function canReschedule(row: ActionableAppointment): boolean {
        return (
            can('appointments.update') &&
            canAct(row) &&
            (row.status === 'confirmed' || row.status === 'rescheduled')
        );
    }

    function canCancel(row: ActionableAppointment): boolean {
        return (
            can('appointments.cancel') &&
            canAct(row) &&
            (row.status === 'confirmed' ||
                row.status === 'rescheduled' ||
                row.status === 'arrived')
        );
    }

    function canDelete(row: ActionableAppointment): boolean {
        // Future-only is server-authoritative; hide for past rows as a UX hint.
        return (
            can('appointments.delete') &&
            canAct(row) &&
            row.status === 'confirmed' &&
            !isPast(row.starts_at)
        );
    }

    function hasActions(row: ActionableAppointment): boolean {
        return canReschedule(row) || canCancel(row) || canDelete(row);
    }

    function goToEdit(row: ActionableAppointment): void {
        router.visit(edit(row.id).url);
    }

    function confirmCancel(row: ActionableAppointment): void {
        cancelReason.value = '';
        confirm.require({
            group: 'appointment-cancel',
            header: t('appointment_actions.cancel_confirm_title'),
            message: t('appointment_actions.cancel_confirm_message'),
            rejectProps: {
                label: t('common.cancel'),
                severity: 'secondary',
                outlined: true,
            },
            acceptProps: {
                label: t('appointment_actions.confirm_cancel'),
                severity: 'danger',
            },
            accept: () =>
                router.patch(
                    cancel(row.id).url,
                    { reason: cancelReason.value || null },
                    { preserveScroll: true, onSuccess: options.onSuccess },
                ),
        });
    }

    function confirmDelete(row: ActionableAppointment): void {
        confirm.require({
            header: t('appointment_actions.delete_confirm_title'),
            message: t('appointment_actions.delete_confirm_message'),
            rejectProps: {
                label: t('common.cancel'),
                severity: 'secondary',
                outlined: true,
            },
            acceptProps: { label: t('common.delete'), severity: 'danger' },
            accept: () =>
                router.delete(destroy(row.id).url, {
                    preserveScroll: true,
                    onSuccess: options.onSuccess,
                }),
        });
    }

    return {
        canViewAll,
        cancelReason,
        canAct,
        canReschedule,
        canCancel,
        canDelete,
        hasActions,
        goToEdit,
        confirmCancel,
        confirmDelete,
    };
}

export type AppointmentActions = ReturnType<typeof useAppointmentActions>;
