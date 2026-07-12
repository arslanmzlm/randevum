<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import WorkingHoursEditor from '@/components/WorkingHoursEditor.vue';
import { useClinicForm } from './formContext';

const { t } = useI18n();

const form = useClinicForm();
</script>

<template>
    <div class="flex flex-col gap-6">
        <FormField
            :label="t('clinic.fields.slot_duration')"
            :error="form.errors.default_slot_duration_minutes"
            :hint="t('clinic.hints.slot_duration')"
            required
        >
            <InputNumber
                v-model="form.default_slot_duration_minutes"
                :min="5"
                :max="480"
                :step="5"
                show-buttons
                suffix=" dk"
                fluid
            />
        </FormField>

        <WorkingHoursEditor
            v-model="form.working_hours"
            :errors="form.errors"
        />
    </div>
</template>
