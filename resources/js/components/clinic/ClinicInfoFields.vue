<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useClinicForm } from './formContext';

defineProps<{
    verticalLabel: string;
}>();

const { t } = useI18n();

const form = useClinicForm();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('clinic.fields.name')"
            :error="form.errors.name"
            required
        >
            <InputText v-model="form.name" fluid />
        </FormField>

        <FormField
            :label="t('clinic.fields.slug')"
            :error="form.errors.slug"
            :hint="t('clinic.hints.slug')"
            required
        >
            <InputText v-model="form.slug" fluid />
        </FormField>

        <FormField
            :label="t('clinic.fields.description')"
            :error="form.errors.description"
        >
            <Textarea v-model="form.description" rows="3" auto-resize fluid />
        </FormField>

        <FormField :label="t('clinic.fields.vertical')">
            <InputText :model-value="verticalLabel" fluid disabled />
        </FormField>
    </div>
</template>
