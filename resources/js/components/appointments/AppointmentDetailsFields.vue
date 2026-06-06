<script setup lang="ts">
import { IconClipboardList } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useAppointmentForm } from './formContext';

const props = defineProps<{
    doctorOptions: Array<{ label: string; value: number }>;
    serviceOptions: Array<{ label: string; value: number }>;
    appointmentTypeOptions: Array<{
        label: string;
        value: number;
        color: string;
    }>;
    doctorLocked: boolean;
}>();

const { t } = useI18n();

const form = useAppointmentForm();

// PrimeVue Select's #value slot hands back the raw value, not the option, so resolve
// the chosen type's label/color from the option list to render the colored swatch.
function colorFor(value: number): string | undefined {
    return props.appointmentTypeOptions.find((o) => o.value === value)?.color;
}

function labelFor(value: number): string | undefined {
    return props.appointmentTypeOptions.find((o) => o.value === value)?.label;
}
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
                <Select
                    v-model="form.appointment_type_id"
                    :options="appointmentTypeOptions"
                    option-label="label"
                    option-value="value"
                    show-clear
                    fluid
                >
                    <template #value="{ value }">
                        <span
                            v-if="value !== null && value !== undefined"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="size-3 shrink-0 rounded-full"
                                :style="{ backgroundColor: colorFor(value) }"
                                :aria-hidden="true"
                            />
                            {{ labelFor(value) }}
                        </span>
                        <!-- FloatLabel passes no placeholder; a non-breaking space keeps the
                             empty label the same height as a selected value (variant="in"
                             reserves the floated-label row), matching the sibling selects. -->
                        <span v-else>&nbsp;</span>
                    </template>
                    <template #option="{ option }">
                        <span class="flex items-center gap-2">
                            <span
                                class="size-3 shrink-0 rounded-full"
                                :style="{ backgroundColor: option.color }"
                                :aria-hidden="true"
                            />
                            {{ option.label }}
                        </span>
                    </template>
                </Select>
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
        </div>
    </div>
</template>
