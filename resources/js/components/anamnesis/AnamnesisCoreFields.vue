<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import type { AnamnesisPatient } from '@/types/anamnesis';
import { useAnamnesisForm } from './formContext';

const props = defineProps<{
    patient: AnamnesisPatient;
    /** Read-only mode (viewer lacks `anamnesis.update`) — every control is disabled. */
    disabled?: boolean;
}>();

const { t } = useI18n();

const form = useAnamnesisForm();

// Allowed values mirrored by hand from the Anamnesis model consts (frontend-components rule
// — validated clinical strings, not branched-on enums). Labels resolve from health.options.*.
const bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'];
const smoking = ['none', 'former', 'occasional', 'regular'];
const alcohol = ['none', 'occasional', 'regular'];
const diabetes = ['none', 'type1', 'type2'];
const pregnancy = ['none', 'pregnant', 'breastfeeding'];

function options(field: string, values: string[]) {
    return values.map((value) => ({
        value,
        label: t(`health.options.${field}.${value}`),
    }));
}

const bloodTypeOptions = computed(() => options('blood_type', bloodTypes));
const smokingOptions = computed(() => options('smoking', smoking));
const alcoholOptions = computed(() => options('alcohol', alcohol));
const diabetesOptions = computed(() => options('diabetes', diabetes));
const pregnancyOptions = computed(() => options('pregnancy', pregnancy));

const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const showWomen = computed(() => props.patient.gender === 'female');

// Derived, never stored — mirrors Anamnesis::bmi() so the form gives live feedback.
const bmi = computed<number | null>(() => {
    const height = form.height_cm;
    const weight = form.weight_kg;

    if (!height || !weight) {
        return null;
    }

    return Math.round((weight / (height / 100) ** 2) * 10) / 10;
});

const booleanFlags = [
    'hypertension',
    'cardiovascular',
    'respiratory',
    'kidney_liver',
    'thyroid',
    'epilepsy',
    'bleeding_disorder',
    'blood_thinners',
    'infectious_disease',
] as const;
</script>

<template>
    <div class="flex flex-col gap-8">
        <div class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.general') }}
            </h3>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <FormField
                    :label="t('health.fields.blood_type')"
                    :error="fieldError('blood_type')"
                >
                    <Select
                        v-model="form.blood_type"
                        :options="bloodTypeOptions"
                        option-label="label"
                        option-value="value"
                        :disabled="disabled"
                        show-clear
                        fluid
                    />
                </FormField>
                <FormField
                    :label="t('health.fields.height_cm')"
                    :error="fieldError('height_cm')"
                >
                    <InputNumber
                        v-model="form.height_cm"
                        :min="1"
                        :max="300"
                        :suffix="` ${t('health.units.cm')}`"
                        :disabled="disabled"
                        fluid
                    />
                </FormField>
                <FormField
                    :label="t('health.fields.weight_kg')"
                    :error="fieldError('weight_kg')"
                >
                    <InputNumber
                        v-model="form.weight_kg"
                        :min="1"
                        :max="500"
                        :min-fraction-digits="0"
                        :max-fraction-digits="2"
                        :suffix="` ${t('health.units.kg')}`"
                        :disabled="disabled"
                        fluid
                    />
                </FormField>

                <!-- Derived from the pair above; read-only, so it stays out of FormField. -->
                <div
                    v-if="bmi !== null"
                    class="flex flex-col justify-center gap-0.5 px-3"
                >
                    <span class="text-xs text-surface-500">
                        {{ t('health.fields.bmi') }}
                    </span>
                    <span class="text-sm font-medium text-surface-900">
                        {{ bmi }} {{ t('health.units.bmi') }}
                    </span>
                </div>

                <FormField
                    :label="t('health.fields.smoking')"
                    :error="fieldError('smoking')"
                >
                    <Select
                        v-model="form.smoking"
                        :options="smokingOptions"
                        option-label="label"
                        option-value="value"
                        :disabled="disabled"
                        show-clear
                        fluid
                    />
                </FormField>
                <FormField
                    :label="t('health.fields.alcohol')"
                    :error="fieldError('alcohol')"
                >
                    <Select
                        v-model="form.alcohol"
                        :options="alcoholOptions"
                        option-label="label"
                        option-value="value"
                        :disabled="disabled"
                        show-clear
                        fluid
                    />
                </FormField>
            </div>
        </div>

        <div class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.systemic') }}
            </h3>
            <FormField
                :label="t('health.fields.diabetes')"
                :error="fieldError('diabetes')"
            >
                <Select
                    v-model="form.diabetes"
                    :options="diabetesOptions"
                    option-label="label"
                    option-value="value"
                    :disabled="disabled"
                    show-clear
                    fluid
                />
            </FormField>

            <div class="flex flex-col gap-3">
                <SettingRow
                    v-for="flag in booleanFlags"
                    :key="flag"
                    :label="t(`health.fields.${flag}`)"
                >
                    <ToggleSwitch v-model="form[flag]" :disabled="disabled" />
                </SettingRow>
            </div>

            <!-- Which disease: only asked once the flag is on. -->
            <FormField
                v-if="form.infectious_disease"
                :label="t('health.fields.infectious_disease_note')"
                :error="fieldError('infectious_disease_note')"
            >
                <InputText
                    v-model="form.infectious_disease_note"
                    :maxlength="500"
                    :disabled="disabled"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('health.fields.regular_medications')"
                :error="fieldError('regular_medications')"
            >
                <Textarea
                    v-model="form.regular_medications"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
            <FormField
                :label="t('health.fields.other_chronic')"
                :error="fieldError('other_chronic')"
            >
                <Textarea
                    v-model="form.other_chronic"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
        </div>

        <div class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.allergy') }}
            </h3>
            <FormField
                :label="t('health.fields.allergies')"
                :error="fieldError('allergies')"
            >
                <Textarea
                    v-model="form.allergies"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
        </div>

        <div class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.history') }}
            </h3>
            <FormField
                :label="t('health.fields.surgery_history')"
                :error="fieldError('surgery_history')"
            >
                <Textarea
                    v-model="form.surgery_history"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
            <FormField
                :label="t('health.fields.family_history')"
                :error="fieldError('family_history')"
            >
                <Textarea
                    v-model="form.family_history"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
        </div>

        <!-- Kadın — pregnancy/menstrual notes are UI-gated to female patients (server stays lenient). -->
        <div v-if="showWomen" class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.women') }}
            </h3>
            <FormField
                :label="t('health.fields.pregnancy')"
                :error="fieldError('pregnancy')"
            >
                <Select
                    v-model="form.pregnancy"
                    :options="pregnancyOptions"
                    option-label="label"
                    option-value="value"
                    :disabled="disabled"
                    show-clear
                    fluid
                />
            </FormField>
            <FormField
                :label="t('health.fields.menstrual_notes')"
                :error="fieldError('menstrual_notes')"
            >
                <Textarea
                    v-model="form.menstrual_notes"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
        </div>
    </div>
</template>
