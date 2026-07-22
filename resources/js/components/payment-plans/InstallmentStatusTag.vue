<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { InstallmentStatus } from '@/types/enums';
import {
    installmentLabelKey,
    installmentSeverity,
} from '@/utils/installmentStatus';

// Localized, consistently-coloured badge for an installment status. `overdue` derives a distinct
// (danger) treatment on top of `pending`; reused on the collections screen + patient plan card.
const props = defineProps<{
    status: InstallmentStatus;
    overdue?: boolean;
    small?: boolean;
}>();

const { t } = useI18n();

const severity = computed(() =>
    installmentSeverity(props.status, props.overdue ?? false),
);
const label = computed(() =>
    t(
        `payment_plan.installment_status.${installmentLabelKey(
            props.status,
            props.overdue ?? false,
        )}`,
    ),
);
</script>

<template>
    <Tag
        :value="label"
        :severity="severity"
        :class="small ? 'p-tag-sm' : undefined"
    />
</template>
