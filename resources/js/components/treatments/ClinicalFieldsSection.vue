<script setup lang="ts">
import { IconStethoscope } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useTreatmentForm } from './formContext';

const { t } = useI18n();

const form = useTreatmentForm();

// Nested dotted error keys (details.*) aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <section
        class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
    >
        <header class="flex items-center gap-2">
            <IconStethoscope class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('treatment.sections.clinical') }}
            </h2>
        </header>

        <FormField
            :label="t('treatment.fields.complaint')"
            :error="fieldError('details.complaint')"
        >
            <Textarea
                v-model="form.details.complaint"
                rows="3"
                auto-resize
                fluid
            />
        </FormField>

        <FormField
            :label="t('treatment.fields.diagnosis')"
            :error="fieldError('details.diagnosis')"
        >
            <Textarea
                v-model="form.details.diagnosis"
                rows="3"
                auto-resize
                fluid
            />
        </FormField>

        <FormField
            :label="t('treatment.fields.treatment_process')"
            :error="fieldError('details.treatment_process')"
        >
            <Textarea
                v-model="form.details.treatment_process"
                rows="3"
                auto-resize
                fluid
            />
        </FormField>

        <FormField
            :label="t('treatment.fields.notes')"
            :error="form.errors.notes"
            :hint="t('treatment.hints.notes')"
        >
            <Textarea v-model="form.notes" rows="2" auto-resize fluid />
        </FormField>
    </section>
</template>
