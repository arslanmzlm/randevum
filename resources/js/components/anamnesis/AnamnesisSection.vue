<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    IconDeviceFloppy,
    IconFileText,
    IconHeartbeat,
} from '@tabler/icons-vue';
import { computed } from 'vue';
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

const props = defineProps<{
    patient: AnamnesisPatient;
    anamnesis: Anamnesis | null;
}>();

const { t } = useI18n();
const { can } = useCan();

const canManage = computed(() => can('anamnesis.update'));
const hasData = computed(() => props.anamnesis !== null);

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
        <template #actions>
            <!-- Binary PDF stream: plain anchor to a new tab, never an Inertia visit. -->
            <Button
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

        <div class="flex flex-col gap-6">
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
