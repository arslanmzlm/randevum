<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { usePatientForm } from './formContext';

const { t } = useI18n();

const form = usePatientForm();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('patient.fields.phone')"
            :error="form.errors.phone"
        >
            <PhoneInput v-model="form.phone" />
        </FormField>

        <FormField
            :label="t('patient.fields.contact_phone')"
            :error="form.errors.contact_phone"
            :hint="t('patient.hints.contact_phone')"
        >
            <PhoneInput v-model="form.contact_phone" />
        </FormField>

        <FormField
            :label="t('patient.fields.email')"
            :error="form.errors.email"
        >
            <InputText v-model="form.email" type="email" fluid />
        </FormField>
    </div>
</template>
