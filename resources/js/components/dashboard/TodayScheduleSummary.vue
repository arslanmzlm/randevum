<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarOff, IconChevronRight, IconWalk } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import RecordName from '@/components/RecordName.vue';
import { useDateTime } from '@/composables/useDateTime';
import { index as calendarIndex } from '@/routes/calendar';
import { show as patientShow } from '@/routes/patients';
import type { TodayScheduleRow } from '@/types/dashboard';

// Read-only "takvim özet": today's schedule glance. Fed by a DEDICATED today-scoped feed
// (stats.today_schedule), NOT the forward-only upcomingAppointments prop — so it shows the
// full clinic-local day including already-started/passed rows (Arrived/Completed) and is not
// hidden by the upcoming widget's small-N cap. Doctor-scoped server-side. Links through to the
// full calendar; no range navigation / drag by design. Gated by appointments.viewAny.
const { t } = useI18n();
const { formatTime } = useDateTime();

defineProps<{
    appointments: TodayScheduleRow[];
}>();
</script>

<template>
    <DashboardPanel>
        <template #title>
            <h2 class="text-base font-semibold text-surface-900">
                {{ t('dashboard.today_schedule.title') }}
            </h2>
        </template>

        <template #actions>
            <Link
                :href="calendarIndex().url"
                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
            >
                {{ t('dashboard.today_schedule.view_calendar') }}
                <IconChevronRight class="size-4" />
            </Link>
        </template>

        <div
            v-if="appointments.length === 0"
            class="flex flex-col items-center gap-2 px-5 py-10 text-center text-sm text-surface-500"
        >
            <IconCalendarOff class="size-7 text-surface-300" />
            {{ t('dashboard.today_schedule.empty') }}
        </div>

        <ul v-else class="flex flex-col">
            <li
                v-for="appointment in appointments"
                :key="appointment.id"
                class="border-b border-surface-200 last:border-b-0"
            >
                <component
                    :is="appointment.patient_is_deleted ? 'div' : Link"
                    :href="
                        appointment.patient_is_deleted
                            ? undefined
                            : patientShow(appointment.patient_id).url
                    "
                    class="flex items-center justify-between gap-3 px-5 py-3"
                    :class="{
                        'transition-colors hover:bg-surface-50':
                            !appointment.patient_is_deleted,
                    }"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-surface-900 tabular-nums"
                        >
                            {{ formatTime(appointment.starts_at) }}
                            <IconWalk
                                v-if="appointment.is_walk_in"
                                class="size-4 text-surface-400"
                                :aria-label="t('appointment.walk_in')"
                            />
                        </span>
                        <div class="flex min-w-0 flex-col">
                            <PatientNameLink
                                plain
                                :name="appointment.patient_name"
                                :deleted="appointment.patient_is_deleted"
                                class="truncate text-sm text-surface-800"
                            />
                            <div
                                class="flex min-w-0 items-center gap-1 text-xs text-surface-500"
                            >
                                <RecordName
                                    :name="appointment.doctor_name"
                                    :deleted="appointment.doctor_is_deleted"
                                    text-class="truncate"
                                />
                                <span
                                    v-if="appointment.service_name"
                                    class="truncate"
                                >
                                    · {{ appointment.service_name }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <AppointmentStatusTag :status="appointment.status" small />
                </component>
            </li>
        </ul>
    </DashboardPanel>
</template>
