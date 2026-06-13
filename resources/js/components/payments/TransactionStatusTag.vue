<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { TransactionStatus } from '@/types/enums';
import { transactionStatusSeverity } from '@/utils/transactionStatus';

// Localized, consistently-colored badge for a transaction status. Reused across both balance
// lists; labels live under the generic `transaction.status.*` lang group.
const props = defineProps<{ status: TransactionStatus }>();

const { t } = useI18n();

const severity = computed(() => transactionStatusSeverity(props.status));
const label = computed(() => t(`transaction.status.${props.status}`));
</script>

<template>
    <Tag :value="label" :severity="severity" />
</template>
