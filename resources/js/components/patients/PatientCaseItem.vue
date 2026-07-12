<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import CaseStatusTag from '@/components/CaseStatusTag.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import { useDateTime } from '@/composables/useDateTime';
import { show as caseShow } from '@/routes/cases';
import type { PatientCaseItem } from '@/types/case';

// Closed cases omit the follow-up date (moot once closed) — open rows pass the default.
withDefaults(
    defineProps<{ caseItem: PatientCaseItem; showFollowUp?: boolean }>(),
    { showFollowUp: true },
);

const { t } = useI18n();
const { formatDateOnly } = useDateTime();
</script>

<template>
    <EntityLinkRow :href="caseShow(caseItem.id).url" :title="caseItem.title">
        <template #status>
            <CaseStatusTag :status="caseItem.status" />
        </template>
        <template #meta>
            {{
                t('patient.cases.treatments_count', {
                    count: caseItem.treatments_count,
                })
            }}
            <template v-if="showFollowUp && caseItem.follow_up_date">
                · {{ formatDateOnly(caseItem.follow_up_date) }}
            </template>
        </template>
    </EntityLinkRow>
</template>
