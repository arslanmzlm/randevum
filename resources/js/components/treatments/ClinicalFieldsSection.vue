<script setup lang="ts">
import { IconStethoscope } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useTreatmentForm } from './formContext';

const { t } = useI18n();

const form = useTreatmentForm();

// Nested dotted error keys (details.*) aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <SectionCard
        :icon="IconStethoscope"
        :title="t('treatment.sections.clinical')"
    >
        <div class="flex flex-col gap-5">
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
        </div>
    </SectionCard>
</template>
