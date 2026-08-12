<script setup lang="ts">
import { Head, useForm, useHttp } from '@inertiajs/vue3';
import { IconCircleCheck, IconClipboardList } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, reactive } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    bulkPrecheck,
    bulkStore,
} from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import AppointmentTopCard from '@/components/appointments/AppointmentTopCard.vue';
import BulkOccurrenceGenerator from '@/components/appointments/bulk/BulkOccurrenceGenerator.vue';
import { provideBulkAppointmentForm } from '@/components/appointments/bulk/formContext';
import { provideBulkGenerator } from '@/components/appointments/bulk/generatorContext';
import type { BulkGeneratorState } from '@/components/appointments/bulk/generatorContext';
import { providePatientForm } from '@/components/appointments/patientFormContext';
import PatientPicker from '@/components/appointments/PatientPicker.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/appointments';
import type {
    BulkAppointmentFormData,
    BulkCreateProps,
    BulkPrecheckPayload,
    BulkPrecheckResponse,
} from '@/types/appointment';
import { combineDateTime } from '@/utils/appointmentTime';
import { shouldFilterSelect } from '@/utils/selectFilter';

defineOptions({ layout: AppLayout });

const props = defineProps<BulkCreateProps>();

const { t } = useI18n();
const { can } = useCan();
const confirm = useConfirm();

const doctorOptions = computed(() =>
    props.doctors.map((doctor) => ({
        label: doctor.display_name,
        value: doctor.id,
    })),
);

// Show the slot length so staff know which duration a service sets; a service with no duration
// falls back to the clinic default, so show the bare name (no empty "· dk").
// Generator params live here so the details card can host count/interval while the generator
// below owns the start slot and the seeds.
const generator = reactive<BulkGeneratorState>({
    date: null,
    time: '',
    count: 4,
    interval: 'weekly',
    interval_days: 7,
    duration_minutes: props.defaultSlotDuration,
    appointment_type_id: null,
});

provideBulkGenerator(generator);

const intervalOptions = computed(() => [
    { value: 'weekly', label: t('treatment.follow_up.interval_weekly') },
    { value: 'biweekly', label: t('treatment.follow_up.interval_biweekly') },
    { value: 'monthly', label: t('treatment.follow_up.interval_monthly') },
    { value: 'custom', label: t('appointment_bulk.generator.interval_custom') },
]);

// Same priority the single-appointment screen uses: service duration → type default → clinic
// default. The generator applies it to the seed and to every row.
const seedDuration = computed(() => {
    const serviceDuration = props.services.find(
        (service) => service.id === form.service_id,
    )?.duration_minutes;
    const typeDuration = props.appointmentTypes.find(
        (type) => type.id === generator.appointment_type_id,
    )?.default_duration_minutes;

    return serviceDuration ?? typeDuration ?? props.defaultSlotDuration;
});

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
// Narrow patient slice shared with the PatientPicker (same component the create page uses).
providePatientForm(form);

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

// Same starts_at derivation the submit transform uses, so the pre-check probes the exact slots.
const precheck = useHttp<BulkPrecheckPayload, BulkPrecheckResponse>(() => ({
    doctor_id: form.doctor_id,
    service_id: form.service_id,
    occurrences: form.occurrences.map((occurrence) => ({
        starts_at: combineDateTime(occurrence.date, occurrence.time),
        duration_minutes: occurrence.duration_minutes,
        appointment_type_id: occurrence.appointment_type_id,
    })),
}));

function post(): void {
    form.post(bulkStore().url);
}

// Warn before submitting when slots would be skipped as conflicts — the server still silently
// skips them, but the receptionist gets to confirm rather than discover it in the result panel.
async function submit(): Promise<void> {
    let conflicts: string[] = [];

    try {
        const response = await precheck.post(bulkPrecheck().url);
        conflicts = response?.conflicts ?? [];
    } catch {
        // Pre-check failed (validation / network / abort) — fall through to the real submit,
        // which is the authoritative gate and surfaces validation errors + the skip report.
        post();

        return;
    }

    if (conflicts.length) {
        confirm.require({
            header: t('common.confirm_title'),
            message: t(
                'appointment_bulk.conflict_confirm',
                { count: conflicts.length },
                conflicts.length,
            ),
            rejectProps: {
                label: t('common.cancel'),
                severity: 'secondary',
                outlined: true,
            },
            acceptProps: { label: t('appointment_bulk.conflict_continue') },
            accept: post,
        });

        return;
    }

    post();
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
            v-if="result"
            class="flex flex-col gap-4 rounded-xl border border-surface-200 bg-surface-0 p-6"
        >
            <Message severity="success" :closable="false">
                {{
                    t(
                        'appointment_bulk.result.created',
                        { count: result.created },
                        result.created,
                    )
                }}
            </Message>

            <div v-if="result.skipped.length" class="flex flex-col gap-2">
                <Message severity="warn" :closable="false">
                    {{
                        t(
                            'appointment_bulk.result.skipped',
                            { count: result.skipped.length },
                            result.skipped.length,
                        )
                    }}
                </Message>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="slot in result.skipped" :key="slot">
                        <Tag severity="warn" :value="slot" />
                    </li>
                </ul>
            </div>
        </div>

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <!-- Same single-card, side-by-side, divided layout as the create page's top card. The
                 date/time section is bulk-specific (the occurrence generator below), so this card
                 carries two columns — patient + appointment details — instead of three. -->
            <AppointmentTopCard :columns="2">
                <PatientPicker :preselected-patient="preselectedPatient" />

                <div
                    class="py-6 first:pt-0 last:pb-0 lg:px-6 lg:py-0 lg:first:pl-0 lg:last:pr-0"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconClipboardList class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('appointment.sections.details') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
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
                                :filter="
                                    shouldFilterSelect(doctorOptions.length)
                                "
                                :filter-placeholder="t('common.search')"
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
                                :filter="
                                    shouldFilterSelect(serviceOptions.length)
                                "
                                :filter-placeholder="t('common.search')"
                                fluid
                            />
                        </FormField>

                        <!-- Count + interval belong with the other "what am I booking" choices;
                             the generator below only owns the start slot and the seeds. -->
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <FormField
                                :label="t('appointment_bulk.generator.count')"
                                :hint="
                                    t('appointment_bulk.generator.count_hint')
                                "
                            >
                                <InputNumber
                                    v-model="generator.count"
                                    :min="1"
                                    :max="12"
                                    show-buttons
                                    :use-grouping="false"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                :label="
                                    t('appointment_bulk.generator.interval')
                                "
                            >
                                <Select
                                    v-model="generator.interval"
                                    :options="intervalOptions"
                                    option-label="label"
                                    option-value="value"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                v-if="generator.interval === 'custom'"
                                :label="
                                    t(
                                        'appointment_bulk.generator.interval_days',
                                    )
                                "
                                :hint="
                                    t(
                                        'appointment_bulk.generator.interval_days_hint',
                                    )
                                "
                                class="sm:col-span-2"
                            >
                                <InputNumber
                                    v-model="generator.interval_days"
                                    :min="1"
                                    :max="365"
                                    show-buttons
                                    :use-grouping="false"
                                    :suffix="` ${t('appointment_bulk.generator.day_suffix')}`"
                                    fluid
                                />
                            </FormField>
                        </div>
                    </div>
                </div>
            </AppointmentTopCard>

            <SectionCard>
                <BulkOccurrenceGenerator
                    :doctor-id="form.doctor_id"
                    :type-options="typeOptions"
                    :seed-duration="seedDuration"
                />
            </SectionCard>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('appointment_bulk.submit')"
                    :loading="form.processing || precheck.processing"
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
