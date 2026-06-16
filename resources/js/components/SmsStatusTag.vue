<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SmsStatus } from '@/types/enums';
import { smsStatusSeverity } from '@/utils/smsStatus';

// Localized, consistently-colored badge for an SMS status. Reused on the clinic-wide
// SMS log list and the patient communication-history section so color + label stay in sync.
const props = defineProps<{ status: SmsStatus; small?: boolean }>();

const { t } = useI18n();

const severity = computed(() => smsStatusSeverity(props.status));
const label = computed(() => t(`sms.status.${props.status}`));
</script>

<template>
    <!-- Tag has no `size` prop; `p-tag-sm` (app.css) is the reusable small variant. -->
    <Tag
        :value="label"
        :severity="severity"
        :class="small ? 'p-tag-sm' : undefined"
    />
</template>
