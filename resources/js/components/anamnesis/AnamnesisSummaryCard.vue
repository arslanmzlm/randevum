<script setup lang="ts">
import { IconChevronRight, IconHeartbeat } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import type { Anamnesis } from '@/types/anamnesis';

// Read-only view of the anamnesis for the patient summary. Filling and editing live on their own
// tab, so this only reports what is on file: filled fields in the form's own order, nothing else.
const props = defineProps<{ anamnesis: Anamnesis | null }>();

const emit = defineEmits<{ edit: [] }>();

const { t } = useI18n();

type Row = { label: string; value: string };

/** Enum-ish fields resolve their label from health.options.<field>.<value>. */
const optionFields = ['smoking', 'alcohol', 'diabetes', 'pregnancy'] as const;

const rows = computed<Row[]>(() => {
    const a = props.anamnesis;

    if (!a) {
        return [];
    }

    const out: Row[] = [];

    const push = (field: string, value: string | null | undefined): void => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        out.push({ label: t(`health.fields.${field}`), value });
    };

    push('blood_type', a.blood_type);
    push(
        'height_cm',
        a.height_cm ? `${a.height_cm} ${t('health.units.cm')}` : null,
    );
    push(
        'weight_kg',
        a.weight_kg ? `${a.weight_kg} ${t('health.units.kg')}` : null,
    );

    optionFields.forEach((field) => {
        const value = a[field];

        if (value) {
            push(field, t(`health.options.${field}.${value}`));
        }
    });

    (['hypertension', 'cardiovascular', 'blood_thinners'] as const).forEach(
        (flag) => {
            if (a[flag]) {
                push(flag, t('health.yes'));
            }
        },
    );

    push('regular_medications', a.regular_medications);
    push('other_chronic', a.other_chronic);
    push('allergies', a.allergies);
    push('foot_surgery_history', a.foot_surgery_history);

    if (a.diabetic_foot_history) {
        push('diabetic_foot_history', t('health.yes'));
    }

    push('current_foot_complaint', a.current_foot_complaint);

    return out;
});
</script>

<template>
    <SectionCard :icon="IconHeartbeat" :title="t('health.clinic_form_title')">
        <template #actions>
            <button
                type="button"
                class="inline-flex cursor-pointer items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                @click="emit('edit')"
            >
                {{ rows.length ? t('health.edit') : t('health.fill') }}
                <IconChevronRight class="size-4" />
            </button>
        </template>

        <dl v-if="rows.length" class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
            <div
                v-for="row in rows"
                :key="row.label"
                class="flex flex-col gap-0.5 border-b border-surface-100 py-2 last:border-0"
            >
                <dt class="text-xs text-surface-500">{{ row.label }}</dt>
                <dd class="text-sm text-surface-900">{{ row.value }}</dd>
            </div>
        </dl>

        <p v-else class="text-sm text-surface-400">
            {{ t('health.empty') }}
        </p>
    </SectionCard>
</template>
