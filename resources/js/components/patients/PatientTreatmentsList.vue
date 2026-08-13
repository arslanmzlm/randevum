<script setup lang="ts">
import { IconNotes } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import RecordName from '@/components/RecordName.vue';
import SectionCard from '@/components/SectionCard.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { show as treatmentShow } from '@/routes/treatments';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';

defineProps<{ treatments: PatientTreatmentHistoryItem[] }>();

const { t } = useI18n();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();
</script>

<template>
    <SectionCard :icon="IconNotes" :title="t('patient.sections.treatments')">
        <ul v-if="treatments.length" class="flex flex-col gap-2">
            <li v-for="item in treatments" :key="item.id">
                <EntityLinkRow
                    :href="treatmentShow(item.id).url"
                    :title="item.title || t('treatment.untitled')"
                    :sub-meta="item.case_title || undefined"
                >
                    <template #status>
                        <TreatmentStatusTag :status="item.status" />
                    </template>
                    <template #meta>
                        <span class="flex min-w-0 items-center gap-1">
                            <span class="shrink-0">
                                {{
                                    item.completed_at
                                        ? formatDate(item.completed_at)
                                        : t('treatment.in_progress')
                                }}
                                ·
                            </span>
                            <RecordName
                                :name="item.doctor_name"
                                :deleted="item.doctor_is_deleted"
                                text-class="truncate"
                            />
                        </span>
                    </template>
                    <template #trailing>
                        <span class="text-sm font-medium text-surface-700">
                            {{ formatMoney(item.total_amount) }}
                        </span>
                    </template>
                </EntityLinkRow>
            </li>
        </ul>

        <EmptyState
            v-else
            variant="dashed"
            :icon="IconNotes"
            :message="t('patient.no_treatments')"
            :description="t('patient.no_treatments_hint')"
        />
    </SectionCard>
</template>
