<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconUser } from '@tabler/icons-vue';
import { computed, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { create as patientCreate } from '@/routes/patients';
import type { PatientSearchResult } from '@/types/patient';
import { usePatientForm } from './patientFormContext';

const props = defineProps<{
    preselectedPatient: PatientSearchResult | null;
}>();

const { t } = useI18n();

const form = usePatientForm();

// FormField isn't used for the search field below: its `invalid` state is intentionally a
// cross-field signal (highlighted when NEITHER an existing patient nor new-patient input is
// present, driven by the new_patient.first_name error, not patient_id), and its error text is
// shown under a different field — FormField couples invalid+message to a single `error` prop,
// which can't represent that split without duplicating or dropping one signal. Still wire a
// real id/for pair since the underlying AutoComplete is a normal text input.
const patientFieldId = useId();

const selectedPatient = ref<PatientSearchResult | null>(
    props.preselectedPatient,
);

watch(selectedPatient, (patient) => {
    form.patient_id = patient?.id ?? null;
});

// Existing-patient search and new-patient fields share the screen but are mutually exclusive:
// typing new-patient details disables the search, and picking a patient disables the fields.
const hasNewPatientInput = computed(() =>
    Object.values(form.new_patient).some((value) => value.trim() !== ''),
);
const hasSelectedPatient = computed(() => selectedPatient.value !== null);

// Nothing chosen on either side — flag the search too so the user sees both ways to supply a patient.
const patientInvalid = computed(
    () =>
        !hasSelectedPatient.value &&
        !hasNewPatientInput.value &&
        Boolean(form.errors['new_patient.first_name']),
);

function clearPatient(): void {
    selectedPatient.value = null;
}
</script>

<template>
    <div
        class="py-6 first:pt-0 last:pb-0 lg:px-6 lg:py-0 lg:first:pl-0 lg:last:pr-0"
    >
        <header class="mb-6 flex items-center gap-2">
            <IconUser class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('appointment.sections.patient') }}
            </h2>
        </header>

        <div class="flex flex-col gap-4">
            <div class="form-group">
                <label :for="patientFieldId" class="mb-1 block text-sm text-muted">
                    {{ t('appointment.fields.patient') }}
                </label>
                <PatientSearchSelect
                    v-model="selectedPatient"
                    :input-id="patientFieldId"
                    :disabled="hasNewPatientInput"
                    :invalid="patientInvalid"
                    :placeholder="t('appointment.patient_placeholder')"
                    class="w-full"
                />
                <button
                    v-if="hasSelectedPatient"
                    type="button"
                    class="mt-1 cursor-pointer self-start text-xs font-medium text-primary-600 hover:text-primary-700 hover:underline"
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

            <div class="flex items-center gap-3 text-xs text-surface-400">
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
                    <InputText v-model="form.new_patient.first_name" fluid />
                </FormField>

                <FormField
                    :label="t('appointment.new_patient.last_name')"
                    :error="form.errors['new_patient.last_name']"
                    required
                >
                    <InputText v-model="form.new_patient.last_name" fluid />
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
    </div>
</template>
