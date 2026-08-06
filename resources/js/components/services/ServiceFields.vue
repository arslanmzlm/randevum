<script setup lang="ts">
import { IconFileText } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import { useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import { useMoney } from '@/composables/useMoney';
import type { ServiceFormData } from '@/types/service';

const { t } = useI18n();

const form = useCrudForm<ServiceFormData>();
const { currency } = useMoney();
</script>

<template>
    <div class="flex flex-col gap-5">
        <FormField
            :label="t('service.fields.name')"
            :error="form.errors.name"
            required
        >
            <InputText v-model="form.name" fluid />
        </FormField>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('service.fields.price')"
                :error="form.errors.price"
                required
            >
                <InputNumber
                    v-model="form.price"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('service.fields.duration_minutes')"
                :error="form.errors.duration_minutes"
                :hint="t('service.hints.duration_minutes')"
            >
                <InputNumber
                    v-model="form.duration_minutes"
                    suffix=" dk"
                    :min="5"
                    :max="480"
                    :step="5"
                    show-buttons
                    fluid
                />
            </FormField>
        </div>

        <FormField
            :label="t('service.fields.description')"
            :error="form.errors.description"
        >
            <Textarea v-model="form.description" rows="2" auto-resize fluid />
        </FormField>

        <SettingRow
            :label="t('service.fields.is_active')"
            :description="t('service.hints.is_active')"
        >
            <ToggleSwitch v-model="form.is_active" />
        </SettingRow>

        <!-- Treatment templates: prefilled text the treatment screen copies in. -->
        <div class="flex flex-col gap-4 border-t border-surface-200 pt-5">
            <div class="flex items-center gap-2">
                <IconFileText class="size-5 text-surface-500" />
                <h3 class="font-semibold text-surface-900">
                    {{ t('service.sections.templates') }}
                </h3>
            </div>

            <p class="text-sm text-surface-500">
                {{ t('service.hints.templates') }}
            </p>

            <FormField
                :label="t('service.fields.default_complaint')"
                :error="form.errors.default_complaint"
            >
                <Textarea
                    v-model="form.default_complaint"
                    rows="2"
                    auto-resize
                    fluid
                />
            </FormField>

            <FormField
                :label="t('service.fields.default_diagnosis')"
                :error="form.errors.default_diagnosis"
            >
                <Textarea
                    v-model="form.default_diagnosis"
                    rows="2"
                    auto-resize
                    fluid
                />
            </FormField>

            <FormField
                :label="t('service.fields.default_treatment_process')"
                :error="form.errors.default_treatment_process"
            >
                <Textarea
                    v-model="form.default_treatment_process"
                    rows="3"
                    auto-resize
                    fluid
                />
            </FormField>
        </div>
    </div>
</template>
