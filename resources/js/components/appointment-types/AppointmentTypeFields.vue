<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import ColorField from '@/components/ColorField.vue';
import { useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import type { AppointmentTypeFormData } from '@/types/appointmentType';
import { COLOR_PRESETS } from '@/utils/colorPresets';

const { t } = useI18n();

const form = useCrudForm<AppointmentTypeFormData>();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('appointment_type.fields.name')"
            :error="form.errors.name"
            required
        >
            <InputText v-model="form.name" fluid />
        </FormField>

        <ColorField
            v-model="form.color"
            :label="t('appointment_type.fields.color')"
            :error="form.errors.color"
            :hint="t('appointment_type.hints.color')"
            :presets="COLOR_PRESETS"
        />

        <FormField
            :label="t('appointment_type.fields.default_duration_minutes')"
            :error="form.errors.default_duration_minutes"
            :hint="t('appointment_type.hints.default_duration_minutes')"
        >
            <InputNumber
                v-model="form.default_duration_minutes"
                suffix=" dk"
                :min="5"
                :max="480"
                :step="5"
                show-buttons
                :use-grouping="false"
                fluid
            />
        </FormField>

        <SettingRow
            :label="t('appointment_type.fields.is_active')"
            :description="t('appointment_type.hints.is_active')"
        >
            <ToggleSwitch v-model="form.is_active" />
        </SettingRow>
    </div>
</template>
