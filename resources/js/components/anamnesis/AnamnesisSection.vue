<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconChevronDown,
    IconChevronUp,
    IconDeviceFloppy,
    IconFileText,
    IconHeartbeat,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { pdf, update } from '@/routes/patients/anamnesis';
import type {
    Anamnesis,
    AnamnesisFormData,
    AnamnesisPatient,
} from '@/types/anamnesis';
import { provideAnamnesisForm } from './formContext';
import PodiatryAnamnesisFields from './PodiatryAnamnesisFields.vue';

// `collapsible` is for screens where the anamnesis is context rather than the task at hand (the
// treatment Process page): the section starts closed and the header carries a one-line summary of
// the risk-bearing fields, so nothing clinically important hides behind a closed panel.
const props = withDefaults(
    defineProps<{
        patient: AnamnesisPatient;
        anamnesis: Anamnesis | null;
        collapsible?: boolean;
    }>(),
    { collapsible: false },
);

const { t } = useI18n();
const { can } = useCan();

const canManage = computed(() => can('anamnesis.update'));
const hasData = computed(() => props.anamnesis !== null);

const open = ref(!props.collapsible);

/** The fields a clinician must not miss before starting a treatment. */
const riskSummary = computed<string[]>(() => {
    const a = props.anamnesis;

    if (!a) {
        return [];
    }

    const parts: string[] = [];

    if (a.diabetes) {
        parts.push(
            `${t('health.fields.diabetes')} ${t(`health.options.diabetes.${a.diabetes}`)}`,
        );
    }

    if (a.allergies) {
        parts.push(`${t('health.fields.allergies')}: ${a.allergies}`);
    }

    if (a.blood_thinners) {
        parts.push(t('health.fields.blood_thinners'));
    }

    if (a.cardiovascular) {
        parts.push(t('health.fields.cardiovascular'));
    }

    if (a.pregnancy) {
        parts.push(t(`health.options.pregnancy.${a.pregnancy}`));
    }

    return parts;
});

function seed(a: Anamnesis | null): AnamnesisFormData {
    return {
        blood_type: a?.blood_type ?? null,
        height_cm: a?.height_cm ?? null,
        weight_kg: a?.weight_kg ?? null,
        smoking: a?.smoking ?? null,
        alcohol: a?.alcohol ?? null,
        diabetes: a?.diabetes ?? null,
        hypertension: a?.hypertension ?? false,
        cardiovascular: a?.cardiovascular ?? false,
        blood_thinners: a?.blood_thinners ?? false,
        regular_medications: a?.regular_medications ?? '',
        other_chronic: a?.other_chronic ?? '',
        allergies: a?.allergies ?? '',
        pregnancy: a?.pregnancy ?? null,
        foot_surgery_history: a?.foot_surgery_history ?? '',
        diabetic_foot_history: a?.diabetic_foot_history ?? false,
        current_foot_complaint: a?.current_foot_complaint ?? '',
    };
}

const form = useForm<AnamnesisFormData>(seed(props.anamnesis));

provideAnamnesisForm(form);

function save(): void {
    form.put(update(props.patient.id).url, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <SectionCard :icon="IconHeartbeat" :title="t('health.clinic_form_title')">
        <template v-if="collapsible" #title>
            <IconHeartbeat class="size-5 shrink-0 text-surface-500" />
            <div class="flex min-w-0 flex-col">
                <h2 class="text-lg font-semibold text-surface-900">
                    {{ t('health.clinic_form_title') }}
                </h2>
                <p
                    v-if="riskSummary.length"
                    class="truncate text-xs text-surface-500"
                >
                    {{ riskSummary.join(' · ') }}
                </p>
                <p
                    v-else-if="!hasData"
                    class="truncate text-xs text-surface-400"
                >
                    {{ t('health.not_filled') }}
                </p>
            </div>
        </template>

        <template #actions>
            <Button
                v-if="collapsible"
                type="button"
                severity="secondary"
                text
                :aria-label="open ? t('health.collapse') : t('health.expand')"
                @click="open = !open"
            >
                <template #icon>
                    <IconChevronDown v-if="!open" />
                    <IconChevronUp v-else />
                </template>
            </Button>

            <!-- Binary PDF stream: plain anchor to a new tab, never an Inertia visit.
                 Hidden until the form has been filled — an empty anamnesis PDF is noise. -->
            <Button
                v-if="hasData"
                as="a"
                :href="pdf(patient.id).url"
                target="_blank"
                rel="noopener"
                severity="secondary"
                outlined
                :label="t('health.download_pdf')"
            >
                <template #icon>
                    <IconFileText />
                </template>
            </Button>
        </template>

        <div v-show="open" class="flex flex-col gap-6">
            <PodiatryAnamnesisFields
                v-if="canManage || hasData"
                :patient="patient"
                :disabled="!canManage"
            />
            <p v-else class="text-sm text-surface-400">
                {{ t('health.empty') }}
            </p>

            <div v-if="canManage" class="flex justify-end">
                <Button
                    type="button"
                    :label="t('health.save')"
                    :loading="form.processing"
                    @click="save"
                >
                    <template #icon>
                        <IconDeviceFloppy />
                    </template>
                </Button>
            </div>
        </div>
    </SectionCard>
</template>
