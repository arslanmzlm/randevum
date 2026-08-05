<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CaseStatus } from '@/types/enums';
import { caseStatusSeverity } from '@/utils/caseStatus';

// Localized, consistently-colored badge for a case status. Reused on the cases list,
// the case detail header and the patient-detail case sections so color + label stay in sync.
const props = defineProps<{ status: CaseStatus }>();

const { t } = useI18n();

const severity = computed(() => caseStatusSeverity(props.status));
const label = computed(() => t(`case.status.${props.status}`));
</script>

<template>
    <Tag :value="label" :severity="severity" class="whitespace-nowrap" />
</template>
