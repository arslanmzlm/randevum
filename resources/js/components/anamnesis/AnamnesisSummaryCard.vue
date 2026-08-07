<script setup lang="ts">
import { IconChevronRight, IconHeartbeat } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useDateTime } from '@/composables/useDateTime';
import type {
    Anamnesis,
    AnamnesisExtraValue,
    AnamnesisFieldDefinition,
} from '@/types/anamnesis';

// Read-only view of the anamnesis for the patient summary. Filling and editing live on their own
// tab, so this only reports what is on file: filled fields in the form's own order, nothing else.
const props = defineProps<{
    anamnesis: Anamnesis | null;
    /**
     * Active + inactive field definitions — a retired definition's stored `extra` value must
     * keep rendering here even though it no longer appears in the form (`anamnesisFields`).
     */
    fields: AnamnesisFieldDefinition[];
}>();

const emit = defineEmits<{ edit: [] }>();

const { t } = useI18n();
const { formatDateOnly } = useDateTime();

type Row = { label: string; value: string };

/** Enum-ish core fields resolve their label from health.options.<field>.<value>. */
const optionFields = ['smoking', 'alcohol', 'diabetes', 'pregnancy'] as const;

const coreFlags = [
    'hypertension',
    'cardiovascular',
    'respiratory',
    'kidney_liver',
    'thyroid',
    'epilepsy',
    'bleeding_disorder',
    'blood_thinners',
    'infectious_disease',
] as const;

function optionLabel(field: AnamnesisFieldDefinition, value: string): string {
    return field.options?.find((o) => o.value === value)?.label ?? value;
}

/** A dynamic answer as display text, or null when the field holds nothing. */
function extraText(
    field: AnamnesisFieldDefinition,
    value: AnamnesisExtraValue | undefined,
): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (field.type === 'boolean') {
        return value ? t('health.yes') : t('health.no');
    }

    if (field.type === 'select') {
        return optionLabel(field, String(value));
    }

    if (field.type === 'multiselect') {
        if (!Array.isArray(value) || value.length === 0) {
            return null;
        }

        return value.map((item) => optionLabel(field, item)).join(', ');
    }

    if (field.type === 'date') {
        return formatDateOnly(String(value));
    }

    return String(value);
}

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
    push('bmi', a.bmi !== null ? `${a.bmi} ${t('health.units.bmi')}` : null);

    optionFields.forEach((field) => {
        const value = a[field];

        if (value) {
            push(field, t(`health.options.${field}.${value}`));
        }
    });

    // Only a raised flag is worth a row; a false one is the unremarkable default.
    coreFlags.forEach((flag) => {
        if (a[flag]) {
            push(flag, t('health.yes'));
        }
    });

    push('infectious_disease_note', a.infectious_disease_note);
    push('regular_medications', a.regular_medications);
    push('other_chronic', a.other_chronic);
    push('allergies', a.allergies);
    push('surgery_history', a.surgery_history);
    push('family_history', a.family_history);
    push('menstrual_notes', a.menstrual_notes);

    props.fields.forEach((field) => {
        const value = extraText(field, a.extra?.[field.key]);

        if (value !== null) {
            out.push({ label: field.label, value });
        }
    });

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
                v-for="(row, index) in rows"
                :key="index"
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
