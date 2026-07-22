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

// Allowed values mirrored by hand from the PodiatryAnamnesis model consts (frontend-components rule
// — validated clinical strings, not branched-on enums). Labels resolve from health.options.*.
const bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'];
const smoking = ['none', 'former', 'active'];
const alcohol = ['none', 'occasional', 'regular'];
const diabetes = ['type1', 'type2'];
const pregnancy = ['pregnant', 'breastfeeding'];

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

const booleanFlags = [
    'hypertension',
    'cardiovascular',
    'blood_thinners',
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

        <!-- Kadın — pregnancy/breastfeeding is UI-gated to female patients (server stays lenient). -->
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
        </div>

        <div class="flex flex-col gap-5">
            <h3 class="text-sm font-semibold text-surface-500">
                {{ t('health.groups.podiatry') }}
            </h3>
            <FormField
                :label="t('health.fields.foot_surgery_history')"
                :error="fieldError('foot_surgery_history')"
            >
                <Textarea
                    v-model="form.foot_surgery_history"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>

            <SettingRow :label="t('health.fields.diabetic_foot_history')">
                <ToggleSwitch
                    v-model="form.diabetic_foot_history"
                    :disabled="disabled"
                />
            </SettingRow>

            <FormField
                :label="t('health.fields.current_foot_complaint')"
                :error="fieldError('current_foot_complaint')"
            >
                <Textarea
                    v-model="form.current_foot_complaint"
                    rows="2"
                    auto-resize
                    :disabled="disabled"
                    fluid
                />
            </FormField>
        </div>
    </div>
</template>
