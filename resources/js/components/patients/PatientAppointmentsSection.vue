<script setup lang="ts">
import { IconCalendarEvent } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import type { PatientAppointmentItem as PatientAppointment } from '@/types/patient';
import PatientAppointmentItem from './PatientAppointmentItem.vue';

// `only` lets the patient tabs place the two halves apart: what is coming up belongs to the
// summary, the history belongs to the clinical tab. Unset keeps the original side-by-side card.
const props = withDefaults(
    defineProps<{
        appointments: PatientAppointment[];
        only?: 'upcoming' | 'past' | null;
    }>(),
    { only: null },
);

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

const showUpcoming = computed(() => props.only !== 'past');
const showPast = computed(() => props.only !== 'upcoming');

const title = computed(() => {
    if (props.only === 'upcoming') {
        return t('patient.appointments.upcoming');
    }

    if (props.only === 'past') {
        return t('patient.appointments.past');
    }

    return t('patient.sections.appointments');
});
</script>

<template>
    <SectionCard
        v-if="can('appointments.viewAny')"
        :icon="IconCalendarEvent"
        :title="title"
    >
        <div
            class="grid grid-cols-1 gap-6"
            :class="only === null ? 'lg:grid-cols-2' : ''"
        >
            <div v-if="showUpcoming" class="flex flex-col gap-2">
                <h3
                    v-if="only === null"
                    class="text-sm font-semibold text-surface-700"
                >
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

            <div v-if="showPast" class="flex flex-col gap-2">
                <h3
                    v-if="only === null"
                    class="text-sm font-semibold text-surface-700"
                >
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
