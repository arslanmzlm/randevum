<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentDetailsFields from '@/components/appointments/AppointmentDetailsFields.vue';
import DateTimeFields from '@/components/appointments/DateTimeFields.vue';
import DaySchedulePanel from '@/components/appointments/DaySchedulePanel.vue';
import { provideAppointmentForm } from '@/components/appointments/formContext';
import PatientPicker from '@/components/appointments/PatientPicker.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { store } from '@/routes/appointments';
import type {
    AppointmentCreateProps,
    AppointmentFormData,
} from '@/types/appointment';
import { combineDateTime } from '@/utils/appointmentTime';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentCreateProps>();

const { t } = useI18n();
const { can } = useCan();

const doctorOptions = computed(() =>
    props.doctors.map((doctor) => ({
        label: doctor.display_name,
        value: doctor.id,
    })),
);

// Service select shows the slot length so staff know which duration it sets; a service with no
// duration falls back to the clinic default, so show the bare name (no empty "· dk").
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

// Type options carry the calendar color so the select renders a colored dot per option.
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

const form = useForm<AppointmentFormData>({
    patient_id: props.preselectedPatient?.id ?? null,
    new_patient: { first_name: '', last_name: '', phone: '', email: '' },
    // Auto-select the user's own doctor profile when they have one (owner-as-doctor / doctor role).
    doctor_id: props.ownDoctorId ?? null,
    service_id: null,
    appointment_type_id: null,
    date: null,
    time: '',
    duration_minutes: props.defaultSlotDuration,
    is_walk_in: false,
});

// Shared with the field partials (PatientPicker / DateTimeFields / AppointmentDetailsFields).
provideAppointmentForm(form);

// Mirrors the backend resolveDuration priority: service duration → appointment-type
// default → clinic default. Editing the duration field afterward is the explicit override.
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

// Without permission to book for others, the doctor select is locked to the user's own profile.
const doctorLocked = computed(() => !can('appointments.assignDoctor'));

form.transform((data) => ({
    // Mode is derived from which side was used — a picked patient wins over typed fields.
    patient_mode: data.patient_id ? 'existing' : 'new',
    patient_id: data.patient_id,
    new_patient: data.patient_id ? null : data.new_patient,
    doctor_id: data.doctor_id,
    service_id: data.service_id,
    appointment_type_id: data.appointment_type_id,
    starts_at: combineDateTime(data.date, data.time),
    duration_minutes: data.duration_minutes,
    is_walk_in: data.is_walk_in,
}));

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment.title')" />

        <PageHeader
            :title="t('appointment.title')"
            :description="t('appointment.subtitle')"
            :breadcrumbs="[{ label: t('appointment.title') }]"
        />

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <section
                class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <div
                    class="grid grid-cols-1 divide-y divide-surface-200 lg:grid-cols-3 lg:divide-x lg:divide-y-0"
                >
                    <PatientPicker :preselected-patient="preselectedPatient" />
                    <DateTimeFields />
                    <AppointmentDetailsFields
                        :doctor-options="doctorOptions"
                        :service-options="serviceOptions"
                        :appointment-type-options="appointmentTypeOptions"
                        :doctor-locked="doctorLocked"
                    />
                </div>
            </section>

            <DaySchedulePanel :doctor-id="form.doctor_id" :date="form.date" />
        </form>
    </div>
</template>
