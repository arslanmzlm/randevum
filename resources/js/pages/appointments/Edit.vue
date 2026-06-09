<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconPhone, IconUser } from '@tabler/icons-vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentDetailsFields from '@/components/appointments/AppointmentDetailsFields.vue';
import DateTimeFields from '@/components/appointments/DateTimeFields.vue';
import DaySchedulePanel from '@/components/appointments/DaySchedulePanel.vue';
import { provideAppointmentForm } from '@/components/appointments/formContext';
import PageHeader from '@/components/PageHeader.vue';
import { useDateTime } from '@/composables/useDateTime';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as appointmentsIndex, update } from '@/routes/appointments';
import { show as patientShow } from '@/routes/patients';
import type {
    AppointmentEditProps,
    AppointmentFormData,
} from '@/types/appointment';
import { combineDateTime } from '@/utils/appointmentTime';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentEditProps>();

const { t } = useI18n();
const { parseUtc } = useDateTime();

const doctorOptions = computed(() =>
    props.doctors.map((doctor) => ({
        label: doctor.display_name,
        value: doctor.id,
    })),
);

const serviceOptions = computed(() =>
    props.services.map((service) => ({
        label: service.duration_minutes
            ? t('appointment.service_option', {
                  name: service.name,
                  minutes: service.duration_minutes,
              })
            : service.name,
        value: service.id,
    })),
);

const appointmentTypeOptions = computed(() =>
    props.appointmentTypes.map((type) => ({
        label: t('appointment.service_option', {
            name: type.name,
            minutes: type.default_duration_minutes,
        }),
        value: type.id,
        color: type.color,
    })),
);

// Split the stored UTC instant into the form's clinic-local Date + "HH:mm" pair. parseUtc returns a
// Date whose LOCAL fields read the clinic wall clock, so reading them back is timezone-safe.
const localStart = parseUtc(props.appointment.starts_at);
const pad = (value: number): string => String(value).padStart(2, '0');

const form = useForm<AppointmentFormData>({
    patient_id: props.appointment.patient.id,
    new_patient: { first_name: '', last_name: '', phone: '', email: '' },
    doctor_id: props.appointment.doctor_id,
    service_id: props.appointment.service_id,
    appointment_type_id: props.appointment.appointment_type_id,
    date: new Date(
        localStart.getFullYear(),
        localStart.getMonth(),
        localStart.getDate(),
    ),
    time: `${pad(localStart.getHours())}:${pad(localStart.getMinutes())}`,
    duration_minutes: props.appointment.duration_minutes,
    is_walk_in: props.appointment.is_walk_in,
});

provideAppointmentForm(form);

// Same resolveDuration priority as create — but only when the staff member changes the
// service/type, so a manual duration override on the existing appointment is preserved on load.
watch(
    [() => form.service_id, () => form.appointment_type_id],
    ([serviceId, typeId]) => {
        const serviceDuration = props.services.find(
            (s) => s.id === serviceId,
        )?.duration_minutes;
        const typeDuration = props.appointmentTypes.find(
            (t) => t.id === typeId,
        )?.default_duration_minutes;
        form.duration_minutes =
            serviceDuration ?? typeDuration ?? props.defaultSlotDuration;
    },
);

// Reschedule edits only the slot + assignment fields; patient and walk-in are fixed here.
form.transform((data) => ({
    doctor_id: data.doctor_id,
    service_id: data.service_id,
    appointment_type_id: data.appointment_type_id,
    starts_at: combineDateTime(data.date, data.time),
    duration_minutes: data.duration_minutes,
}));

function submit(): void {
    form.put(update(props.appointment.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_actions.edit_title')" />

        <PageHeader
            :title="t('appointment_actions.edit_title')"
            :description="t('appointment_actions.subtitle')"
            :breadcrumbs="[
                { label: t('nav.appointments'), href: appointmentsIndex().url },
                { label: t('appointment_actions.edit_title') },
            ]"
        />

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <section
                class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <div
                    class="grid grid-cols-1 divide-y divide-surface-200 lg:grid-cols-3 lg:divide-x lg:divide-y-0"
                >
                    <div
                        class="py-6 first:pt-0 last:pb-0 lg:px-6 lg:py-0 lg:first:pl-0 lg:last:pr-0"
                    >
                        <header class="mb-6 flex items-center gap-2">
                            <IconUser class="size-5 text-surface-500" />
                            <h2 class="text-lg font-semibold text-surface-900">
                                {{ t('appointment.sections.patient') }}
                            </h2>
                        </header>

                        <div
                            class="flex flex-col gap-3 rounded-lg border border-surface-200 p-4"
                        >
                            <Link
                                :href="patientShow(appointment.patient.id).url"
                                class="font-medium text-primary-600 transition-colors hover:text-primary-700"
                            >
                                {{ appointment.patient.full_name }}
                            </Link>
                            <span
                                v-if="appointment.patient.phone"
                                class="flex items-center gap-2 text-sm text-surface-500"
                            >
                                <IconPhone class="size-4 shrink-0" />
                                {{ appointment.patient.phone }}
                            </span>
                            <p class="text-xs text-surface-400">
                                {{
                                    t('appointment_actions.patient_fixed_hint')
                                }}
                            </p>
                        </div>
                    </div>

                    <DateTimeFields
                        :exclude-appointment-id="appointment.id"
                        :submit-label="t('appointment_actions.save')"
                    />
                    <AppointmentDetailsFields
                        :doctor-options="doctorOptions"
                        :service-options="serviceOptions"
                        :appointment-type-options="appointmentTypeOptions"
                        :doctor-locked="true"
                        :show-walk-in="false"
                    />
                </div>
            </section>

            <DaySchedulePanel :doctor-id="form.doctor_id" :date="form.date" />
        </form>
    </div>
</template>
