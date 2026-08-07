<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { StockMovementReason } from '@/types/enums';
import { stockMovementReasonSeverity } from '@/utils/stockMovementReason';

// Localized, consistently-colored badge for a stock-movement reason.
const props = defineProps<{ reason: StockMovementReason; small?: boolean }>();

const { t } = useI18n();

const severity = computed(() => stockMovementReasonSeverity(props.reason));
const label = computed(() => t(`stock_movement.reason.${props.reason}`));
</script>

<template>
    <!-- Tag has no `size` prop; `p-tag-sm` (app.css) is the reusable small variant. -->
    <Tag
        :value="label"
        :severity="severity"
        class="whitespace-nowrap"
        :class="small ? 'p-tag-sm' : undefined"
    />
</template>
