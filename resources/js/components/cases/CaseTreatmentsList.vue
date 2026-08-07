<script setup lang="ts">
import { IconLink, IconStethoscope, IconUnlink } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import SectionCard from '@/components/SectionCard.vue';
import TreatmentStatusTag from '@/components/TreatmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { show as treatmentShow } from '@/routes/treatments';
import type { CaseDetail, CaseTreatmentItem } from '@/types/case';

defineProps<{
    caseRecord: CaseDetail;
    canLink: boolean;
    ungroupedCount: number;
}>();

defineEmits<{ openLink: []; unlink: [treatmentId: number] }>();

const { t } = useI18n();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();

function clinicalRows(
    item: CaseTreatmentItem,
): Array<{ label: string; value: string }> {
    return (
        [
            ['complaint', item.complaint],
            ['diagnosis', item.diagnosis],
            ['treatment_process', item.treatment_process],
        ] as const
    )
        .filter(([, value]) => !!value?.trim())
        .map(([field, value]) => ({
            label: t(`treatment.fields.${field}`),
            value: value as string,
        }));
}
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
                <!-- The unlink control sits OUTSIDE the row link: EntityLinkRow renders as an
                     anchor, and a button nested in one is invalid markup. -->
                <div class="flex items-center gap-2">
                    <EntityLinkRow
                        class="min-w-0 flex-1"
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

                    <Button
                        v-if="canLink"
                        type="button"
                        severity="danger"
                        text
                        size="small"
                        :aria-label="t('case.link.remove')"
                        v-tooltip.top="t('case.link.remove')"
                        @click="$emit('unlink', item.id)"
                    >
                        <IconUnlink class="size-4" />
                    </Button>
                </div>

                <!-- What was actually done, without opening each treatment. Only the filled
                     fields render, so a bare row stays bare. -->
                <dl
                    v-if="clinicalRows(item).length"
                    class="mt-1 ml-3 flex flex-col gap-1 border-l border-surface-200 py-1 pl-3"
                >
                    <div
                        v-for="row in clinicalRows(item)"
                        :key="row.label"
                        class="flex flex-col gap-0.5 sm:flex-row sm:gap-2"
                    >
                        <dt class="shrink-0 text-xs text-surface-400 sm:w-32">
                            {{ row.label }}
                        </dt>
                        <dd class="text-xs text-surface-600">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
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
