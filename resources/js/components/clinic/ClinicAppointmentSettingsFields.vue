<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import { useClinicForm } from './formContext';

const { t } = useI18n();

const form = useClinicForm();
</script>

<template>
    <div class="flex flex-col gap-6">
        <SettingRow
            :label="t('clinic.fields.auto_no_show_enabled')"
            :description="t('clinic.hints.auto_no_show_enabled')"
        >
            <ToggleSwitch v-model="form.auto_no_show_enabled" />
        </SettingRow>

        <FormField
            :label="t('clinic.fields.auto_no_show_grace_hours')"
            :error="form.errors.auto_no_show_grace_hours"
            :hint="t('clinic.hints.auto_no_show_grace_hours')"
            required
        >
            <InputNumber
                v-model="form.auto_no_show_grace_hours"
                :min="0"
                :max="168"
                :step="1"
                show-buttons
                :suffix="` ${t('common.units.hour')}`"
                :disabled="!form.auto_no_show_enabled"
                fluid
            />
        </FormField>
    </div>
</template>
