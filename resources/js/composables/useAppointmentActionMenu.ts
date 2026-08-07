import {
    IconBan,
    IconBell,
    IconClipboardPlus,
    IconPencil,
    IconTrash,
    IconUserCheck,
    IconUserX,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';
import type {
    ActionableAppointment,
    AppointmentActions,
} from '@/composables/useAppointmentActions';
import type { TreatmentActions } from '@/composables/useTreatmentActions';

/** Everything the lifecycle + treatment gates need about one appointment. */
export type AppointmentActionTarget = ActionableAppointment & {
    treatment_id: number | null;
};

export type AppointmentActionItem = {
    key: string;
    label: string;
    icon: Component;
    run: () => void;
};

export type AppointmentActionMenuOptions = {
    /** Runs before every action — the calendar popover passes its `hide`. */
    beforeRun?: () => void;
};

/**
 * Categorizes the appointment lifecycle actions into one primary action, secondary menu rows and
 * a destructive group, from the same gates the server enforces. Shared by the calendar popover and
 * the appointment detail page so the two surfaces can never offer different actions for the same
 * appointment. Pair with one `<AppointmentCancelDialog v-model="cancelReason" />` on the host page.
 */
export function useAppointmentActionMenu(
    actions: AppointmentActions,
    treatmentActions: TreatmentActions,
    target: ComputedRef<AppointmentActionTarget | null>,
    options: AppointmentActionMenuOptions = {},
) {
    const { t } = useI18n();

    function item(
        key: string,
        label: string,
        icon: Component,
        run: (row: AppointmentActionTarget) => void,
    ): AppointmentActionItem {
        return {
            key,
            label,
            icon,
            run: () => {
                const row = target.value;

                if (!row) {
                    return;
                }

                options.beforeRun?.();
                run(row);
            },
        };
    }

    /**
     * The one action this state is really about: continue the treatment if there is one to work on,
     * otherwise check the patient in, otherwise reschedule. Everything else is a menu row.
     */
    const primaryAction = computed<AppointmentActionItem | null>(() => {
        const row = target.value;

        if (!row) {
            return null;
        }

        if (treatmentActions.canStartTreatment(row)) {
            return item(
                'treatment',
                treatmentActions.isResume(row)
                    ? t('treatment.actions.resume')
                    : t('treatment.actions.start'),
                IconClipboardPlus,
                (r) => treatmentActions.startTreatment(r),
            );
        }

        if (actions.canCheckIn(row)) {
            return item(
                'check-in',
                t('appointment_actions.menu.check_in'),
                IconUserCheck,
                (r) => actions.checkIn(r),
            );
        }

        if (actions.canReschedule(row)) {
            return item(
                'edit',
                t('appointment_actions.menu.edit'),
                IconPencil,
                (r) => actions.goToEdit(r),
            );
        }

        return null;
    });

    const secondaryActions = computed<AppointmentActionItem[]>(() => {
        const row = target.value;

        if (!row) {
            return [];
        }

        const all: AppointmentActionItem[] = [];

        if (actions.canCheckIn(row)) {
            all.push(
                item(
                    'check-in',
                    t('appointment_actions.menu.check_in'),
                    IconUserCheck,
                    (r) => actions.checkIn(r),
                ),
            );
        }

        if (actions.canReschedule(row)) {
            all.push(
                item(
                    'edit',
                    t('appointment_actions.menu.edit'),
                    IconPencil,
                    (r) => actions.goToEdit(r),
                ),
            );
        }

        if (actions.canSendReminder(row)) {
            all.push(
                item(
                    'send-reminder',
                    t('appointment_actions.send_reminder'),
                    IconBell,
                    (r) => actions.confirmSendReminder(r),
                ),
            );
        }

        // Whatever was promoted to the primary slot must not repeat below it.
        return all.filter((action) => action.key !== primaryAction.value?.key);
    });

    const destructiveActions = computed<AppointmentActionItem[]>(() => {
        const row = target.value;

        if (!row) {
            return [];
        }

        const all: AppointmentActionItem[] = [];

        if (actions.canMarkNoShow(row)) {
            all.push(
                item(
                    'no-show',
                    t('appointment_actions.menu.no_show'),
                    IconUserX,
                    (r) => actions.confirmMarkNoShow(r),
                ),
            );
        }

        if (actions.canCancel(row)) {
            all.push(
                item(
                    'cancel',
                    t('appointment_actions.menu.cancel'),
                    IconBan,
                    (r) => actions.confirmCancel(r),
                ),
            );
        }

        if (actions.canDelete(row)) {
            all.push(
                item(
                    'delete',
                    t('appointment_actions.menu.delete'),
                    IconTrash,
                    (r) => actions.confirmDelete(r),
                ),
            );
        }

        return all;
    });

    return { primaryAction, secondaryActions, destructiveActions };
}
