<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import { usePatientForm } from './formContext';

const { t } = useI18n();

const form = usePatientForm();
</script>

<template>
    <div class="flex flex-col gap-5">
        <SettingRow
            :label="t('patient.fields.notification_enabled')"
            :description="t('patient.hints.notification_enabled')"
        >
            <ToggleSwitch v-model="form.notification_enabled" />
        </SettingRow>

        <SettingRow
            :label="t('patient.fields.is_legacy')"
            :description="t('patient.hints.is_legacy')"
        >
            <ToggleSwitch v-model="form.is_legacy" />
        </SettingRow>

        <FormField
            :label="t('patient.fields.notes')"
            :error="form.errors.notes"
        >
            <Textarea v-model="form.notes" rows="3" auto-resize fluid />
        </FormField>
    </div>
</template>
