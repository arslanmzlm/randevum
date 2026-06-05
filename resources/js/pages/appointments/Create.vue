<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    IconCalendarEvent,
    IconClipboardList,
    IconClockHour4,
    IconUser,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store } from '@/routes/appointments';
import { create as patientCreate } from '@/routes/patients';
import type {
    AppointmentCreateProps,
    AppointmentFormData,
} from '@/types/appointment';
import type { PatientSearchResult } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentCreateProps>();

const { t } = useI18n();

const doctorOptions = computed(() =>
    props.doctors.map((doctor) => ({
        label: doctor.display_name,
        value: doctor.id,
    })),
);

// Service select shows the slot length so staff know which duration it sets.
const serviceOptions = computed(() =>
    props.services.map((service) => ({
        label: t('appointment.service_option', {
            name: service.name,
            minutes: service.duration_minutes,
        }),
        value: service.id,
    })),
);

const selectedPatient = ref<PatientSearchResult | null>(
    props.preselectedPatient,
);

const form = useForm<AppointmentFormData>({
    patient_id: props.preselectedPatient?.id ?? null,
    new_patient: { first_name: '', last_name: '', phone: '', email: '' },
    // Auto-select the user's own doctor profile when they have one (owner-as-doctor / doctor role).
    doctor_id: props.ownDoctorId ?? null,
    service_id: null,
    date: null,
    time: '',
    duration_minutes: props.defaultSlotDuration,
    is_walk_in: false,
});

watch(selectedPatient, (patient) => {
    form.patient_id = patient?.id ?? null;
});

// Existing-patient search and new-patient fields share the screen but are mutually exclusive:
// typing new-patient details disables the search, and picking a patient disables the fields.
const hasNewPatientInput = computed(() =>
    Object.values(form.new_patient).some((value) => value.trim() !== ''),
);
const hasSelectedPatient = computed(() => selectedPatient.value !== null);

function clearPatient(): void {
    selectedPatient.value = null;
}

// Selecting a service drives the slot length; clearing it falls back to the clinic default.
watch(
    () => form.service_id,
    (id) => {
        const service = props.services.find((s) => s.id === id);
        form.duration_minutes = service
            ? service.duration_minutes
            : props.defaultSlotDuration;
    },
);

// Without permission to book for others, the doctor select is locked to the user's own profile.
const doctorLocked = computed(() => !props.canAssignDoctor);

// starts_at is a server-only key (the transform builds it from date + time), so it isn't part of
// the form's typed error map — read it through a loosened view.
const startsAtError = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>).starts_at,
);

const TIME_PATTERN = /^([01]?\d|2[0-3]):([0-5]\d)$/;
const validTime = computed(() => TIME_PATTERN.test(form.time));

// A starts_at error means the combined value was rejected; flag the specific empty/invalid side
// (inline pickers have no input element, so we also surface a ring + message, not just :invalid).
const dateInvalid = computed(() => Boolean(startsAtError.value) && !form.date);
const timeInvalid = computed(
    () => Boolean(startsAtError.value) && !validTime.value,
);

// Nothing chosen on either side — flag the search too so the user sees both ways to supply a patient.
const patientInvalid = computed(
    () =>
        !hasSelectedPatient.value &&
        !hasNewPatientInput.value &&
        Boolean(form.errors['new_patient.first_name']),
);

// Date (inline picker) + "HH:mm" time string merge into one clinic-local wall-clock string;
// the server interprets it in the clinic timezone. Returns null until both are valid.
function combineDateTime(date: Date | null, time: string): string | null {
    const match = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(time);

    if (!date || !match) {
        return null;
    }

    const pad = (value: number): string => String(value).padStart(2, '0');

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(Number(match[1]))}:${match[2]}:00`
    );
}

form.transform((data) => ({
    // Mode is derived from which side was used — a picked patient wins over typed fields.
    patient_mode: data.patient_id ? 'existing' : 'new',
    patient_id: data.patient_id,
    new_patient: data.patient_id ? null : data.new_patient,
    doctor_id: data.doctor_id,
    service_id: data.service_id,
    starts_at: combineDateTime(data.date, data.time),
    duration_minutes: data.duration_minutes,
    is_walk_in: data.is_walk_in,
}));

const minDate = new Date();

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
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                <!-- Patient card: search an existing patient or fill the new-patient fields (mutually exclusive). -->
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconUser class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('appointment.sections.patient') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-4">
                        <div class="form-group">
                            <label class="mb-1 block text-sm text-muted">
                                {{ t('appointment.fields.patient') }}
                            </label>
                            <PatientSearchSelect
                                v-model="selectedPatient"
                                :disabled="hasNewPatientInput"
                                :invalid="patientInvalid"
                                :placeholder="
                                    t('appointment.patient_placeholder')
                                "
                                class="w-full"
                            />
                            <button
                                v-if="hasSelectedPatient"
                                type="button"
                                class="mt-1 self-start text-xs font-medium text-primary-600 hover:text-primary-700"
                                @click="clearPatient"
                            >
                                {{ t('appointment.clear_patient') }}
                            </button>
                            <small
                                v-if="form.errors.patient_id"
                                class="text-xs text-red-500"
                            >
                                {{ form.errors.patient_id }}
                            </small>
                        </div>

                        <div
                            class="flex items-center gap-3 text-xs text-surface-400"
                        >
                            <span class="h-px flex-1 bg-surface-200" />
                            {{ t('appointment.new_patient.divider') }}
                            <span class="h-px flex-1 bg-surface-200" />
                        </div>

                        <fieldset
                            :disabled="hasSelectedPatient"
                            class="m-0 flex min-w-0 flex-col gap-4 border-0 p-0 disabled:opacity-60"
                        >
                            <FormField
                                :label="t('appointment.new_patient.first_name')"
                                :error="form.errors['new_patient.first_name']"
                                required
                            >
                                <InputText
                                    v-model="form.new_patient.first_name"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                :label="t('appointment.new_patient.last_name')"
                                :error="form.errors['new_patient.last_name']"
                                required
                            >
                                <InputText
                                    v-model="form.new_patient.last_name"
                                    fluid
                                />
                            </FormField>

                            <FormField
                                :label="t('appointment.new_patient.phone')"
                                :error="form.errors['new_patient.phone']"
                            >
                                <PhoneInput v-model="form.new_patient.phone" />
                            </FormField>

                            <FormField
                                :label="t('appointment.new_patient.email')"
                                :error="form.errors['new_patient.email']"
                            >
                                <InputText
                                    v-model="form.new_patient.email"
                                    type="email"
                                    fluid
                                />
                            </FormField>

                            <p class="text-xs text-surface-500">
                                {{ t('appointment.new_patient.hint') }}
                            </p>
                        </fieldset>

                        <Link
                            :href="patientCreate().url"
                            class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-700"
                        >
                            <IconUser class="size-3.5" />
                            {{ t('appointment.new_patient_link') }}
                        </Link>
                    </div>
                </section>

                <!-- Date & time card: inline calendar + masked time, merged server-side into starts_at. -->
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconCalendarEvent class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('appointment.sections.datetime') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-4">
                        <div class="form-group">
                            <label class="mb-1 block text-sm text-muted">
                                {{ t('appointment.fields.date')
                                }}<span class="text-red-500"> *</span>
                            </label>
                            <DatePicker
                                v-model="form.date"
                                inline
                                :min-date="minDate"
                                :invalid="dateInvalid"
                                class="w-full"
                                :class="{ 'datepicker-invalid': dateInvalid }"
                            />
                        </div>

                        <div class="form-group">
                            <label class="mb-1 block text-sm text-muted">
                                {{ t('appointment.fields.time')
                                }}<span class="text-red-500"> *</span>
                            </label>
                            <InputMask
                                v-model="form.time"
                                mask="99:99"
                                :placeholder="t('appointment.time_placeholder')"
                                :invalid="timeInvalid"
                                fluid
                            />
                            <small
                                v-if="startsAtError"
                                class="text-xs text-red-500"
                            >
                                {{ startsAtError }}
                            </small>
                        </div>
                    </div>
                </section>

                <!-- Details card: doctor, service, duration, walk-in. -->
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6"
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

                        <FormField
                            :label="t('appointment.fields.duration_minutes')"
                            :error="form.errors.duration_minutes"
                            :hint="t('appointment.hints.duration_minutes')"
                        >
                            <InputNumber
                                v-model="form.duration_minutes"
                                :min="5"
                                :max="480"
                                :step="5"
                                suffix=" dk"
                                show-buttons
                                :use-grouping="false"
                                fluid
                            />
                        </FormField>

                        <div
                            class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                        >
                            <span class="text-sm font-medium text-surface-900">
                                {{ t('appointment.fields.is_walk_in') }}
                            </span>
                            <ToggleSwitch v-model="form.is_walk_in" />
                        </div>

                        <p class="text-xs text-surface-500">
                            {{ t('appointment.hints.is_walk_in') }}
                        </p>
                    </div>
                </section>
            </div>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('appointment.submit')"
                    :loading="form.processing"
                >
                    <template #icon>
                        <IconClockHour4 />
                    </template>
                </Button>
            </div>
        </form>
    </div>
</template>
