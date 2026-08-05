<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconBan,
    IconBell,
    IconBriefcase,
    IconClockHour4,
    IconClipboardPlus,
    IconPencil,
    IconStethoscope,
    IconTag,
    IconTrash,
    IconUser,
    IconUserCheck,
    IconUserX,
    IconWalk,
} from '@tabler/icons-vue';
import { computed, nextTick, ref  } from 'vue';
import type {Component} from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import type { AppointmentActions } from '@/composables/useAppointmentActions';
import { useDateTime } from '@/composables/useDateTime';
import type { TreatmentActions } from '@/composables/useTreatmentActions';
import { show as patientShow } from '@/routes/patients';
import type { CalendarEventDto } from '@/types/calendar';

// Summary of a clicked appointment, anchored to its chip. The lifecycle actions (edit / cancel /
// delete) reuse the page's shared useAppointmentActions instance so the calendar and the list
// never drift. Exposes show()/hide() so the page drives it imperatively.
const props = defineProps<{
    actions: AppointmentActions;
    treatmentActions: TreatmentActions;
}>();

const { t } = useI18n();
const { formatDateOnly } = useDateTime();

const popover = ref();
const appointment = ref<CalendarEventDto | null>(null);

async function show(event: Event, value: CalendarEventDto): Promise<void> {
    appointment.value = value;
    popover.value?.show(event);
    // PrimeVue positions the panel only on its enter transition. When it's already open and the user
    // clicks another chip, show() swaps the target but skips repositioning, so it stays anchored to
    // the previous chip. Realign once the new content has rendered (harmless redundant align on first
    // open — it targets the same chip).
    await nextTick();
    popover.value?.alignOverlay();
}

function hide(): void {
    popover.value?.hide();
}

defineExpose({ show, hide });

const timeLabel = computed(() => {
    const a = appointment.value;

    if (!a) {
        return '';
    }

    // Server start/end are clinic-local 'YYYY-MM-DD HH:mm' wall-clock strings — slice the date and
    // time parts directly (no tz math, matching the chip). Using formatRange here would wrongly
    // re-apply the clinic offset to an already-local time.
    return `${formatDateOnly(a.start.slice(0, 10))} ${a.start.slice(11, 16)} – ${a.end.slice(11, 16)}`;
});

// Map the calendar event to the shape the shared action gates expect (event uses `start`).
const actionable = computed(() =>
    appointment.value
        ? {
              id: appointment.value.id,
              doctor_id: appointment.value.doctor_id,
              status: appointment.value.status,
              starts_at: appointment.value.start,
          }
        : null,
);

// Treatment gate needs treatment_id (start vs resume); status/doctor mirror the list row.
const treatable = computed(() =>
    appointment.value
        ? {
              id: appointment.value.id,
              doctor_id: appointment.value.doctor_id,
              status: appointment.value.status,
              treatment_id: appointment.value.treatment_id,
          }
        : null,
);

function onTreatment(): void {
    if (treatable.value) {
        hide();
        props.treatmentActions.startTreatment(treatable.value);
    }
}

function onCheckIn(): void {
    if (actionable.value) {
        hide();
        props.actions.checkIn(actionable.value);
    }
}

function onMarkNoShow(): void {
    if (actionable.value) {
        hide();
        props.actions.confirmMarkNoShow(actionable.value);
    }
}

function onEdit(): void {
    if (actionable.value) {
        props.actions.goToEdit(actionable.value);
    }
}

function onSendReminder(): void {
    if (actionable.value) {
        hide();
        props.actions.confirmSendReminder(actionable.value);
    }
}

function onCancel(): void {
    if (actionable.value) {
        hide();
        props.actions.confirmCancel(actionable.value);
    }
}

function onDelete(): void {
    if (actionable.value) {
        hide();
        props.actions.confirmDelete(actionable.value);
    }
}

type PopoverAction = {
    label: string;
    icon: Component;
    run: () => void;
};

/**
 * The one action this state is really about: continue the treatment if there is one to work on,
 * otherwise check the patient in, otherwise reschedule. Everything else is a menu row.
 */
const primaryAction = computed<PopoverAction | null>(() => {
    if (
        treatable.value &&
        props.treatmentActions.canStartTreatment(treatable.value)
    ) {
        return {
            label: props.treatmentActions.isResume(treatable.value)
                ? t('treatment.actions.resume')
                : t('treatment.actions.start'),
            icon: IconClipboardPlus,
            run: onTreatment,
        };
    }

    if (actionable.value && props.actions.canCheckIn(actionable.value)) {
        return {
            label: t('appointment_actions.menu.check_in'),
            icon: IconUserCheck,
            run: onCheckIn,
        };
    }

    if (actionable.value && props.actions.canReschedule(actionable.value)) {
        return {
            label: t('appointment_actions.menu.edit'),
            icon: IconPencil,
            run: onEdit,
        };
    }

    return null;
});

const secondaryActions = computed<PopoverAction[]>(() => {
    const a = actionable.value;

    if (!a) {
        return [];
    }

    const all: PopoverAction[] = [];

    if (props.actions.canCheckIn(a)) {
        all.push({
            label: t('appointment_actions.menu.check_in'),
            icon: IconUserCheck,
            run: onCheckIn,
        });
    }

    if (props.actions.canReschedule(a)) {
        all.push({
            label: t('appointment_actions.menu.edit'),
            icon: IconPencil,
            run: onEdit,
        });
    }

    if (props.actions.canSendReminder(a)) {
        all.push({
            label: t('appointment_actions.send_reminder'),
            icon: IconBell,
            run: onSendReminder,
        });
    }

    // Whatever was promoted to the primary slot must not repeat below it.
    return all.filter((action) => action.label !== primaryAction.value?.label);
});

const destructiveActions = computed<PopoverAction[]>(() => {
    const a = actionable.value;

    if (!a) {
        return [];
    }

    const all: PopoverAction[] = [];

    if (props.actions.canMarkNoShow(a)) {
        all.push({
            label: t('appointment_actions.menu.no_show'),
            icon: IconUserX,
            run: onMarkNoShow,
        });
    }

    if (props.actions.canCancel(a)) {
        all.push({
            label: t('appointment_actions.menu.cancel'),
            icon: IconBan,
            run: onCancel,
        });
    }

    if (props.actions.canDelete(a)) {
        all.push({
            label: t('appointment_actions.menu.delete'),
            icon: IconTrash,
            run: onDelete,
        });
    }

    return all;
});

const rows = computed(() => {
    const a = appointment.value;

    if (!a) {
        return [];
    }

    return [
        {
            icon: IconStethoscope,
            label: t('calendar.popover.doctor'),
            value: a.doctor_name,
        },
        {
            icon: IconClockHour4,
            label: t('calendar.popover.time'),
            value: timeLabel.value,
        },
        {
            icon: IconBriefcase,
            label: t('calendar.popover.service'),
            value: a.service_name,
        },
        {
            icon: IconTag,
            label: t('calendar.popover.type'),
            value: a.type_name,
            color: a.type_color,
        },
    ].filter((row) => row.value);
});
</script>

<template>
    <Popover ref="popover">
        <div v-if="appointment" class="flex w-72 flex-col gap-3">
            <header class="flex flex-col gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <IconUser class="size-5 shrink-0 text-surface-400" />
                    <Link
                        :href="patientShow(appointment.patient_id).url"
                        class="truncate font-semibold text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                    >
                        {{ appointment.title }}
                    </Link>
                </div>
                <!-- Status (+ walk-in) on their own row so a long label can't squeeze the name. -->
                <div class="flex flex-wrap items-center gap-2">
                    <AppointmentStatusTag :status="appointment.status" />
                    <Tag v-if="appointment.is_walk_in" severity="warn">
                        <template #icon>
                            <IconWalk class="size-3.5" />
                        </template>
                        {{ t('calendar.walk_in') }}
                    </Tag>
                </div>
            </header>

            <dl class="flex flex-col gap-2 text-sm">
                <div
                    v-for="row in rows"
                    :key="row.label"
                    class="flex items-center gap-2"
                >
                    <component
                        :is="row.icon"
                        class="size-4 shrink-0 text-surface-400"
                    />
                    <dt class="shrink-0 text-surface-500">{{ row.label }}:</dt>
                    <dd
                        class="flex min-w-0 items-center gap-1.5 font-medium text-surface-800"
                    >
                        <span
                            v-if="row.color"
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: row.color }"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ row.value }}</span>
                    </dd>
                </div>
            </dl>

            <!-- One primary action (what the receptionist reaches for in this state), the rest as
                 menu rows — the same vocabulary as the appointment list's ⋮ menu. Five equal
                 buttons wrapped into a ragged block before. -->
            <footer
                v-if="
                    primaryAction ||
                    secondaryActions.length ||
                    destructiveActions.length
                "
                class="flex flex-col gap-2 border-t border-surface-200 pt-3"
            >
                <Button
                    v-if="primaryAction"
                    type="button"
                    size="small"
                    :label="primaryAction.label"
                    class="w-full"
                    @click="primaryAction.run()"
                >
                    <template #icon>
                        <component :is="primaryAction.icon" class="size-4" />
                    </template>
                </Button>

                <div v-if="secondaryActions.length" class="flex flex-col">
                    <button
                        v-for="action in secondaryActions"
                        :key="action.label"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-surface-700 transition-colors hover:bg-surface-100"
                        @click="action.run()"
                    >
                        <component
                            :is="action.icon"
                            class="size-4 shrink-0 text-surface-400"
                        />
                        {{ action.label }}
                    </button>
                </div>

                <div
                    v-if="destructiveActions.length"
                    class="flex flex-col border-t border-surface-200 pt-2"
                >
                    <button
                        v-for="action in destructiveActions"
                        :key="action.label"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-red-600 transition-colors hover:bg-red-50"
                        @click="action.run()"
                    >
                        <component :is="action.icon" class="size-4 shrink-0" />
                        {{ action.label }}
                    </button>
                </div>
            </footer>
        </div>
    </Popover>
</template>
