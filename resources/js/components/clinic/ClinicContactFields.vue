<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useClinicForm } from './formContext';

const { t } = useI18n();

const form = useClinicForm();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField :label="t('clinic.fields.phone')" :error="form.errors.phone">
            <InputText v-model="form.phone" type="tel" fluid />
        </FormField>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('clinic.fields.email')"
                :error="form.errors.email"
            >
                <InputText v-model="form.email" type="email" fluid />
            </FormField>

            <FormField
                :label="t('clinic.fields.website')"
                :error="form.errors.website"
            >
                <InputText v-model="form.website" type="url" fluid />
            </FormField>
        </div>
    </div>
</template>
