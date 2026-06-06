<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AppointmentStatus } from '@/types/enums';
import { appointmentStatusSeverity } from '@/utils/appointmentStatus';

// Localized, consistently-colored badge for an appointment status. Reused wherever
// a status is shown (day panel, list, calendar) so color + label stay in sync.
const props = defineProps<{ status: AppointmentStatus }>();

const { t } = useI18n();

const severity = computed(() => appointmentStatusSeverity(props.status));
const label = computed(() => t(`appointment.status.${props.status}`));
</script>

<template>
    <Tag :value="label" :severity="severity" />
</template>
