<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TreatmentStatus } from '@/types/enums';
import { treatmentStatusSeverity } from '@/utils/treatmentStatus';

// Localized, consistently-colored badge for a treatment status. Reused on the Show page,
// the patient treatment-history list and anywhere a treatment status is surfaced.
const props = defineProps<{ status: TreatmentStatus }>();

const { t } = useI18n();

const severity = computed(() => treatmentStatusSeverity(props.status));
const label = computed(() => t(`treatment.status.${props.status}`));
</script>

<template>
    <Tag :value="label" :severity="severity" class="whitespace-nowrap" />
</template>
