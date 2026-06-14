<script setup lang="ts">
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
    IconWalk,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import type { AppointmentActions } from '@/composables/useAppointmentActions';
import { useDateTime } from '@/composables/useDateTime';
import type { TreatmentActions } from '@/composables/useTreatmentActions';
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

function show(event: Event, value: CalendarEventDto): void {
    appointment.value = value;
    popover.value?.show(event);
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
                    <span class="truncate font-semibold text-surface-900">
                        {{ appointment.title }}
                    </span>
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

            <footer
                v-if="
                    (actionable && actions.hasActions(actionable)) ||
                    (treatable && treatmentActions.canStartTreatment(treatable))
                "
                class="flex flex-wrap gap-2 border-t border-surface-200 pt-3"
            >
                <Button
                    v-if="
                        treatable &&
                        treatmentActions.canStartTreatment(treatable)
                    "
                    type="button"
                    size="small"
                    severity="primary"
                    :label="
                        treatmentActions.isResume(treatable)
                            ? t('treatment.actions.resume')
                            : t('treatment.actions.start')
                    "
                    @click="onTreatment"
                >
                    <template #icon
                        ><IconClipboardPlus class="size-4"
                    /></template>
                </Button>
                <Button
                    v-if="actionable && actions.canReschedule(actionable)"
                    type="button"
                    size="small"
                    severity="primary"
                    outlined
                    :label="t('appointment_actions.menu.edit')"
                    @click="onEdit"
                >
                    <template #icon><IconPencil class="size-4" /></template>
                </Button>
                <Button
                    v-if="actionable && actions.canSendReminder(actionable)"
                    type="button"
                    size="small"
                    severity="primary"
                    outlined
                    :label="t('appointment_actions.send_reminder')"
                    @click="onSendReminder"
                >
                    <template #icon><IconBell class="size-4" /></template>
                </Button>
                <Button
                    v-if="actionable && actions.canCancel(actionable)"
                    type="button"
                    size="small"
                    severity="warn"
                    outlined
                    :label="t('appointment_actions.menu.cancel')"
                    @click="onCancel"
                >
                    <template #icon><IconBan class="size-4" /></template>
                </Button>
                <Button
                    v-if="actionable && actions.canDelete(actionable)"
                    type="button"
                    size="small"
                    severity="danger"
                    outlined
                    :label="t('appointment_actions.menu.delete')"
                    @click="onDelete"
                >
                    <template #icon><IconTrash class="size-4" /></template>
                </Button>
            </footer>
        </div>
    </Popover>
</template>
