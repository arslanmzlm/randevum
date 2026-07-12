<script setup lang="ts">
import { IconCalendarEvent } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import type { PatientAppointmentItem as PatientAppointment } from '@/types/patient';
import PatientAppointmentItem from './PatientAppointmentItem.vue';

const props = defineProps<{ appointments: PatientAppointment[] }>();

const { t } = useI18n();
const { can } = useCan();
const { isPast } = useDateTime();

// Upcoming vs past split client-side; an in-progress one counts as upcoming until it ends.
// Server sends newest-first, so upcoming reverses to soonest-first.
const upcomingAppointments = computed<PatientAppointment[]>(() =>
    props.appointments.filter((a) => !isPast(a.ends_at)).reverse(),
);
const pastAppointments = computed<PatientAppointment[]>(() =>
    props.appointments.filter((a) => isPast(a.ends_at)),
);
</script>

<template>
    <SectionCard
        v-if="can('appointments.viewAny')"
        :icon="IconCalendarEvent"
        :title="t('patient.sections.appointments')"
    >
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-2">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('patient.appointments.upcoming') }}
                </h3>
                <ul
                    v-if="upcomingAppointments.length"
                    class="flex flex-col gap-2"
                >
                    <li v-for="item in upcomingAppointments" :key="item.id">
                        <PatientAppointmentItem :appointment="item" />
                    </li>
                </ul>
                <p v-else class="text-sm text-surface-400">
                    {{ t('patient.appointments.no_upcoming') }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('patient.appointments.past') }}
                </h3>
                <ul v-if="pastAppointments.length" class="flex flex-col gap-2">
                    <li v-for="item in pastAppointments" :key="item.id">
                        <PatientAppointmentItem :appointment="item" />
                    </li>
                </ul>
                <p v-else class="text-sm text-surface-400">
                    {{ t('patient.appointments.no_past') }}
                </p>
            </div>
        </div>
    </SectionCard>
</template>
