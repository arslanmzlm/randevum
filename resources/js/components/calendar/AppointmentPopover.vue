<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconBriefcase,
    IconClockHour4,
    IconFileDescription,
    IconStethoscope,
    IconTag,
    IconUser,
    IconWalk,
} from '@tabler/icons-vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import RecordName from '@/components/RecordName.vue';
import { useAppointmentActionMenu } from '@/composables/useAppointmentActionMenu';
import type { AppointmentActionTarget } from '@/composables/useAppointmentActionMenu';
import type { AppointmentActions } from '@/composables/useAppointmentActions';
import { useDateTime } from '@/composables/useDateTime';
import type { TreatmentActions } from '@/composables/useTreatmentActions';
import { show as appointmentShow } from '@/routes/appointments';
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
const target = computed<AppointmentActionTarget | null>(() =>
    appointment.value
        ? {
              id: appointment.value.id,
              doctor_id: appointment.value.doctor_id,
              doctor_is_deleted: appointment.value.doctor_is_deleted,
              status: appointment.value.status,
              starts_at: appointment.value.starts_at_utc,
              treatment_id: appointment.value.treatment_id,
          }
        : null,
);

const { primaryAction, secondaryActions, destructiveActions } =
    useAppointmentActionMenu(
        props.actions,
        props.treatmentActions,
        target,
        // Every action navigates or opens a dialog — close the panel first.
        { beforeRun: hide },
    );

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
            deleted: a.doctor_is_deleted,
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
                    <PatientNameLink
                        :id="appointment.patient_id"
                        :name="appointment.title"
                        :deleted="appointment.patient_is_deleted"
                        class="truncate font-semibold"
                    />
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
                        <RecordName
                            :name="row.value ?? ''"
                            :deleted="row.deleted ?? false"
                            text-class="truncate"
                        />
                    </dd>
                </div>
            </dl>

            <!-- One primary action (what the receptionist reaches for in this state), the rest as
                 menu rows — the same vocabulary as the appointment list's ⋮ menu. Five equal
                 buttons wrapped into a ragged block before. -->
            <footer
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

                <!-- "Detaya git" always closes the group: the popover is a summary, the detail
                     page is where the status history and SMS live. -->
                <div class="flex flex-col">
                    <button
                        v-for="action in secondaryActions"
                        :key="action.key"
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
                    <Link
                        :href="appointmentShow(appointment.id).url"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-surface-700 transition-colors hover:bg-surface-100"
                    >
                        <IconFileDescription
                            class="size-4 shrink-0 text-surface-400"
                        />
                        {{ t('appointment_actions.menu.detail') }}
                    </Link>
                </div>

                <div
                    v-if="destructiveActions.length"
                    class="flex flex-col border-t border-surface-200 pt-2"
                >
                    <button
                        v-for="action in destructiveActions"
                        :key="action.key"
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
