<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCircleCheck,
    IconClipboardList,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { bulkStore } from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import BulkOccurrenceGenerator from '@/components/appointments/bulk/BulkOccurrenceGenerator.vue';
import BulkPatientPicker from '@/components/appointments/bulk/BulkPatientPicker.vue';
import { provideBulkAppointmentForm } from '@/components/appointments/bulk/formContext';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/appointments';
import type {
    BulkAppointmentFormData,
    BulkCreateProps,
} from '@/types/appointment';
import { combineDateTime } from '@/utils/appointmentTime';

defineOptions({ layout: AppLayout });

const props = defineProps<BulkCreateProps>();

const { t } = useI18n();
const { can } = useCan();

const doctorOptions = computed(() =>
    props.doctors.map((doctor) => ({
        label: doctor.display_name,
        value: doctor.id,
    })),
);

// Show the slot length so staff know which duration a service sets; a service with no duration
// falls back to the clinic default, so show the bare name (no empty "· dk").
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

const typeOptions = computed(() =>
    props.appointmentTypes.map((type) => ({
        label: type.name,
        value: type.id,
        color: type.color,
    })),
);

const form = useForm<BulkAppointmentFormData>({
    patient_id: props.preselectedPatient?.id ?? null,
    new_patient: { first_name: '', last_name: '', phone: '', email: '' },
    // Auto-select the user's own doctor profile when they have one (owner-as-doctor / doctor role).
    doctor_id: props.ownDoctorId ?? null,
    service_id: null,
    occurrences: [],
});

provideBulkAppointmentForm(form);

// Without permission to book for others, the doctor select is locked to the user's own profile.
const doctorLocked = computed(() => !can('appointments.assignDoctor'));

form.transform((data) => ({
    // Mode is derived from which side was used — a picked patient wins over typed fields.
    patient_mode: data.patient_id ? 'existing' : 'new',
    patient_id: data.patient_id,
    new_patient: data.patient_id ? null : data.new_patient,
    doctor_id: data.doctor_id,
    service_id: data.service_id,
    occurrences: data.occurrences.map((occurrence) => ({
        starts_at: combineDateTime(occurrence.date, occurrence.time),
        duration_minutes: occurrence.duration_minutes,
        appointment_type_id: occurrence.appointment_type_id,
    })),
}));

function submit(): void {
    form.post(bulkStore().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_bulk.title')" />

        <PageHeader
            :title="t('appointment_bulk.title')"
            :description="t('appointment_bulk.subtitle')"
            :breadcrumbs="[
                { label: t('appointment_list.title'), href: index().url },
                { label: t('appointment_bulk.title') },
            ]"
        />

        <!-- Post-book report: created count + the slots that conflicted and were skipped. -->
        <div
            v-if="props.result"
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6"
        >
            <p
                class="flex items-center gap-2 text-sm font-medium text-green-600"
            >
                <IconCircleCheck class="size-5 shrink-0" />
                {{
                    t(
                        'appointment_bulk.result.created',
                        { count: props.result.created },
                        props.result.created,
                    )
                }}
            </p>

            <div v-if="props.result.skipped.length" class="flex flex-col gap-2">
                <p
                    class="flex items-center gap-2 text-sm font-medium text-amber-600"
                >
                    <IconAlertTriangle class="size-5 shrink-0" />
                    {{
                        t(
                            'appointment_bulk.result.skipped',
                            { count: props.result.skipped.length },
                            props.result.skipped.length,
                        )
                    }}
                </p>
                <ul class="flex flex-wrap gap-2 pl-7 text-sm text-surface-600">
                    <li
                        v-for="slot in props.result.skipped"
                        :key="slot"
                        class="rounded-md bg-amber-50 px-2 py-1 text-amber-700"
                    >
                        {{ slot }}
                    </li>
                </ul>
            </div>
        </div>

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <SectionCard>
                <BulkPatientPicker :preselected-patient="preselectedPatient" />
            </SectionCard>

            <SectionCard
                :icon="IconClipboardList"
                :title="t('appointment.sections.details')"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField
                        :label="t('appointment.fields.doctor')"
                        :error="form.errors.doctor_id"
                        required
                    >
                        <Select
                            v-model="form.doctor_id"
                            :options="doctorOptions"
                            option-label="label"
                            option-value="value"
                            :disabled="doctorLocked"
                            fluid
                        />
                    </FormField>

                    <FormField
                        :label="t('appointment.fields.service')"
                        :error="form.errors.service_id"
                        :hint="t('appointment.hints.service')"
                    >
                        <Select
                            v-model="form.service_id"
                            :options="serviceOptions"
                            option-label="label"
                            option-value="value"
                            show-clear
                            fluid
                        />
                    </FormField>
                </div>
            </SectionCard>

            <SectionCard>
                <BulkOccurrenceGenerator
                    :doctor-id="form.doctor_id"
                    :type-options="typeOptions"
                    :default-slot-duration="defaultSlotDuration"
                />
            </SectionCard>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('appointment_bulk.submit')"
                    :loading="form.processing"
                    :disabled="!form.occurrences.length"
                >
                    <template #icon>
                        <IconCircleCheck class="size-4" />
                    </template>
                </Button>
            </div>
        </form>
    </div>
</template>
