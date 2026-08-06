<script setup lang="ts">
import { IconClipboardList } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import AppointmentTypeSelect from '@/components/AppointmentTypeSelect.vue';
import FormField from '@/components/FormField.vue';
import { useAppointmentForm } from './formContext';

withDefaults(
    defineProps<{
        doctorOptions: Array<{ label: string; value: number }>;
        serviceOptions: Array<{ label: string; value: number }>;
        appointmentTypeOptions: Array<{
            label: string;
            value: number;
            color: string;
        }>;
        doctorLocked: boolean;
        /** Walk-in is set at creation and not editable when rescheduling. */
        showWalkIn?: boolean;
    }>(),
    { showWalkIn: true },
);

const { t } = useI18n();

const form = useAppointmentForm();
</script>

<template>
    <div
        class="py-6 first:pt-0 last:pb-0 lg:px-6 lg:py-0 lg:first:pl-0 lg:last:pr-0"
    >
        <header class="mb-6 flex items-center gap-2">
            <IconClipboardList class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('appointment.sections.details') }}
            </h2>
        </header>

        <div class="flex flex-col gap-5">
            <FormField
                :label="t('appointment.fields.doctor')"
                :error="form.errors.doctor_id"
                required
            >
                <Select
                    v-model="form.doctor_id"
                    :options="doctorOptions"
                    option-label="label"
                    option-value="value"
                    :disabled="doctorLocked"
                    fluid
                />
            </FormField>

            <FormField
                v-if="appointmentTypeOptions.length"
                :label="t('appointment.fields.appointment_type')"
                :error="form.errors.appointment_type_id"
                :hint="t('appointment.hints.appointment_type')"
            >
                <AppointmentTypeSelect
                    v-model="form.appointment_type_id"
                    :options="appointmentTypeOptions"
                    creatable
                />
            </FormField>

            <FormField
                :label="t('appointment.fields.service')"
                :error="form.errors.service_id"
                :hint="t('appointment.hints.service')"
            >
                <Select
                    v-model="form.service_id"
                    :options="serviceOptions"
                    option-label="label"
                    option-value="value"
                    show-clear
                    fluid
                />
            </FormField>

            <FormField
                :label="t('appointment.fields.duration_minutes')"
                :error="form.errors.duration_minutes"
                :hint="t('appointment.hints.duration_minutes')"
            >
                <InputNumber
                    v-model="form.duration_minutes"
                    :min="5"
                    :max="480"
                    :step="5"
                    suffix=" dk"
                    show-buttons
                    :use-grouping="false"
                    fluid
                />
            </FormField>

            <template v-if="showWalkIn">
                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                >
                    <span class="text-sm font-medium text-surface-900">
                        {{ t('appointment.fields.is_walk_in') }}
                    </span>
                    <ToggleSwitch v-model="form.is_walk_in" />
                </div>

                <p class="text-xs text-surface-500">
                    {{ t('appointment.hints.is_walk_in') }}
                </p>
            </template>
        </div>
    </div>
</template>
