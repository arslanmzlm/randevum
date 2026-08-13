<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarOff, IconLoader2 } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import { useDateTime } from '@/composables/useDateTime';
import { show as patientShow } from '@/routes/patients';
import type { UpcomingAppointmentDto } from '@/types/appointment';

// Prop-driven so both the header widget and (later) the dashboard right-rail can reuse it.
withDefaults(
    defineProps<{
        appointments: UpcomingAppointmentDto[];
        loading?: boolean;
    }>(),
    { loading: false },
);

const { t } = useI18n();
const { formatDateTime } = useDateTime();
</script>

<template>
    <div>
        <div
            v-if="loading && appointments.length === 0"
            class="flex items-center gap-2 px-4 py-6 text-sm text-surface-500"
        >
            <IconLoader2 class="size-4 animate-spin" />
            {{ t('upcoming_appointments.loading') }}
        </div>

        <div
            v-else-if="appointments.length === 0"
            class="flex flex-col items-center gap-2 px-4 py-8 text-center text-sm text-surface-500"
        >
            <IconCalendarOff class="size-6 text-surface-400" />
            {{ t('upcoming_appointments.empty') }}
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
                    class="flex items-start justify-between gap-3 px-4 py-3"
                    :class="{
                        'transition-colors hover:bg-surface-100':
                            !appointment.patient_is_deleted,
                    }"
                >
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <PatientNameLink
                            plain
                            :name="appointment.patient_name"
                            :deleted="appointment.patient_is_deleted"
                            class="truncate text-sm font-medium text-surface-900"
                        />
                        <span class="text-xs text-surface-500">
                            {{ formatDateTime(appointment.starts_at) }}
                        </span>
                        <span
                            v-if="
                                appointment.appointment_type ||
                                appointment.service_name
                            "
                            class="flex items-center gap-1.5 text-xs text-surface-400"
                        >
                            <span
                                v-if="appointment.appointment_type"
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor:
                                        appointment.appointment_type.color,
                                }"
                                :aria-hidden="true"
                            />
                            <span class="truncate">
                                {{
                                    appointment.appointment_type?.name ??
                                    appointment.service_name
                                }}
                            </span>
                        </span>
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <AppointmentStatusTag
                            :status="appointment.status"
                            small
                        />
                        <Tag
                            v-if="appointment.is_walk_in"
                            :value="t('appointment.walk_in')"
                            severity="secondary"
                            class="p-tag-sm"
                        />
                    </div>
                </component>
            </li>
        </ul>
    </div>
</template>
