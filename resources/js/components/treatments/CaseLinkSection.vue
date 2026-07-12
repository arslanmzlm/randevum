<script setup lang="ts">
import { IconFolder } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import ModeSelectRow from '@/components/ModeSelectRow.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import type { CaseMode, OpenCaseOption } from '@/types/treatment';
import { daysSince } from '@/utils/datetime';
import { useTreatmentForm } from './formContext';

const props = defineProps<{ openCases: OpenCaseOption[] }>();

const { t } = useI18n();
const { can } = useCan();

const form = useTreatmentForm();

const canCreateCase = computed(() => can('cases.create'));

// "none" is always offered; "existing" only with open cases; "new" only with the permission.
const modeOptions = computed<Array<{ value: CaseMode; label: string }>>(() => {
    const options: Array<{ value: CaseMode; label: string }> = [];

    if (props.openCases.length) {
        options.push({
            value: 'existing',
            label: t('treatment.case.mode_existing'),
        });
    }

    if (canCreateCase.value) {
        options.push({ value: 'new', label: t('treatment.case.mode_new') });
    }

    options.push({ value: 'none', label: t('treatment.case.mode_none') });

    return options;
});

// "{title} — {vaka yaşı}, {N} ziyaret" — case age derived from opened_at as a coarse duration.
function caseAge(openedAt: string): string {
    const days = Math.max(0, daysSince(openedAt));

    if (days < 7) {
        return t('treatment.case.age_days', { count: days });
    }

    if (days < 30) {
        return t('treatment.case.age_weeks', { count: Math.floor(days / 7) });
    }

    return t('treatment.case.age_months', { count: Math.floor(days / 30) });
}

const caseOptions = computed(() =>
    props.openCases.map((c) => ({
        value: c.id,
        label: t('treatment.case.option', {
            title: c.title,
            age: caseAge(c.opened_at),
            count: c.treatments_count,
        }),
    })),
);
</script>

<template>
    <SectionCard :icon="IconFolder" :title="t('treatment.sections.case')">
        <div class="flex flex-col gap-5">
            <ModeSelectRow
                v-model="form.case_mode"
                :options="modeOptions"
                id-prefix="case-mode"
            />

            <FormField
                v-if="form.case_mode === 'existing'"
                :label="t('treatment.case.select_label')"
                :error="form.errors.case_id"
            >
                <Select
                    v-model="form.case_id"
                    :options="caseOptions"
                    option-label="label"
                    option-value="value"
                    fluid
                />
            </FormField>

            <FormField
                v-else-if="form.case_mode === 'new'"
                :label="t('treatment.case.new_title_label')"
                :error="form.errors.new_case_title"
                required
            >
                <InputText v-model="form.new_case_title" fluid />
            </FormField>
        </div>
    </SectionCard>
</template>
