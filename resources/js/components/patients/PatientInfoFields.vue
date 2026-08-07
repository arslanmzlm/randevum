<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { usePatientForm } from './formContext';

const { t } = useI18n();

const form = usePatientForm();

const genderOptions = computed(() => [
    { label: t('patient.gender.male'), value: 'male' as const },
    { label: t('patient.gender.female'), value: 'female' as const },
    { label: t('patient.gender.other'), value: 'other' as const },
]);

const maritalOptions = computed(() =>
    (['single', 'married', 'divorced', 'widowed'] as const).map((value) => ({
        label: t(`patient.marital_status.${value}`),
        value,
    })),
);

const maxBirthDate = new Date();
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('patient.fields.first_name')"
                :error="form.errors.first_name"
                required
            >
                <InputText v-model="form.first_name" fluid />
            </FormField>

            <FormField
                :label="t('patient.fields.last_name')"
                :error="form.errors.last_name"
                required
            >
                <InputText v-model="form.last_name" fluid />
            </FormField>
        </div>

        <FormField
            :label="t('patient.fields.birth_date')"
            :error="form.errors.birth_date"
        >
            <DatePicker
                v-model="form.birth_date"
                date-format="dd.mm.yy"
                :max-date="maxBirthDate"
                fluid
            />
        </FormField>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('patient.fields.gender')"
                :error="form.errors.gender"
            >
                <Select
                    v-model="form.gender"
                    :options="genderOptions"
                    option-label="label"
                    option-value="value"
                    show-clear
                    fluid
                />
            </FormField>

            <FormField
                :label="t('patient.fields.marital_status')"
                :error="form.errors.marital_status"
            >
                <Select
                    v-model="form.marital_status"
                    :options="maritalOptions"
                    option-label="label"
                    option-value="value"
                    show-clear
                    fluid
                />
            </FormField>
        </div>

        <FormField
            :label="t('patient.fields.occupation')"
            :error="form.errors.occupation"
        >
            <InputText v-model="form.occupation" :maxlength="100" fluid />
        </FormField>
    </div>
</template>
