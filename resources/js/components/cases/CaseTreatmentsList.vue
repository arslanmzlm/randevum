<script setup lang="ts">
import { IconLink, IconStethoscope } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import SectionCard from '@/components/SectionCard.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { show as treatmentShow } from '@/routes/treatments';
import type { CaseDetail } from '@/types/case';

defineProps<{
    caseRecord: CaseDetail;
    canLink: boolean;
    ungroupedCount: number;
}>();

defineEmits<{ openLink: [] }>();

const { t } = useI18n();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();
</script>

<template>
    <SectionCard :icon="IconStethoscope" :title="t('case.sections.treatments')">
        <template #actions>
            <Button
                v-if="canLink && ungroupedCount"
                type="button"
                severity="secondary"
                outlined
                size="small"
                :label="t('case.link.add')"
                @click="$emit('openLink')"
            >
                <template #icon>
                    <IconLink class="size-4" />
                </template>
            </Button>
        </template>

        <ul v-if="caseRecord.treatments.length" class="flex flex-col gap-2">
            <li v-for="item in caseRecord.treatments" :key="item.id">
                <EntityLinkRow
                    :href="treatmentShow(item.id).url"
                    :title="item.title || t('treatment.untitled')"
                    :meta="
                        item.completed_at
                            ? formatDate(item.completed_at)
                            : t('treatment.in_progress')
                    "
                >
                    <template #status>
                        <TreatmentStatusTag :status="item.status" />
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
            :icon="IconStethoscope"
            :message="t('case.no_treatments')"
        />
    </SectionCard>
</template>
