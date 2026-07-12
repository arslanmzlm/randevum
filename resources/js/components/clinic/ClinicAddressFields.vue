<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import type { ClinicCity, ClinicCountry } from '@/types/clinic';
import { useClinicForm } from './formContext';

defineProps<{
    countries: ClinicCountry[];
    cities: ClinicCity[];
}>();

const { t } = useI18n();

const form = useClinicForm();
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('clinic.fields.country')"
                :error="form.errors.country_id"
                required
            >
                <Select
                    v-model="form.country_id"
                    :options="countries"
                    option-label="name"
                    option-value="id"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('clinic.fields.city')"
                :error="form.errors.city_id"
            >
                <Select
                    v-model="form.city_id"
                    :options="cities"
                    option-label="name"
                    option-value="id"
                    show-clear
                    filter
                    fluid
                />
            </FormField>
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('clinic.fields.district')"
                :error="form.errors.district"
            >
                <InputText v-model="form.district" fluid />
            </FormField>

            <FormField
                :label="t('clinic.fields.postal_code')"
                :error="form.errors.postal_code"
            >
                <InputText v-model="form.postal_code" fluid />
            </FormField>
        </div>

        <FormField
            :label="t('clinic.fields.address')"
            :error="form.errors.address"
        >
            <Textarea v-model="form.address" rows="2" auto-resize fluid />
        </FormField>
    </div>
</template>
