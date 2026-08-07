<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { FollowUpStatus } from '@/types/enums';
import { followUpStatusSeverity } from '@/utils/followUpStatus';

// Localized, consistently-colored badge for a follow-up status. Reused by the case panel and
// the history list so color + label stay in sync.
const props = withDefaults(
    defineProps<{
        status: FollowUpStatus;
        isOverdue?: boolean;
        small?: boolean;
    }>(),
    { isOverdue: false, small: false },
);

const { t } = useI18n();

const severity = computed(() =>
    followUpStatusSeverity(props.status, props.isOverdue),
);
const label = computed(() => t(`follow_up.status.${props.status}`));
</script>

<template>
    <Tag
        :value="label"
        :severity="severity"
        class="whitespace-nowrap"
        :class="small ? 'p-tag-sm' : undefined"
    />
</template>
