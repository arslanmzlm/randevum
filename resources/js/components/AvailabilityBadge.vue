<script setup lang="ts">
import { IconAlertTriangle, IconCheck, IconLoader2 } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { AvailabilityState } from '@/composables/useAvailabilityCheck';
import type { AvailabilityReason } from '@/types/appointment';

// Single home for the availability state→icon/colour/message mapping, shared by the
// create-appointment date/time fields and both follow-up modes (single + per package row).
const props = defineProps<{
    state: AvailabilityState;
    reason: AvailabilityReason | null;
}>();

const { t } = useI18n();

const message = computed<string | null>(() => {
    switch (props.state) {
        case 'checking':
            return t('appointment.availability.checking');
        case 'available':
            return t('appointment.availability.available');
        case 'unavailable':
            return props.reason
                ? t(`appointment.errors.${props.reason}`)
                : t('appointment.availability.unavailable');
        default:
            return null;
    }
});
</script>

<template>
    <p
        v-if="message"
        class="flex items-center gap-1.5 text-xs"
        :class="{
            'text-surface-500': state === 'checking',
            'text-green-600': state === 'available',
            'text-amber-600': state === 'unavailable',
        }"
    >
        <IconLoader2
            v-if="state === 'checking'"
            class="size-3.5 animate-spin"
        />
        <IconCheck v-else-if="state === 'available'" class="size-3.5" />
        <IconAlertTriangle v-else class="size-3.5" />
        {{ message }}
    </p>
</template>
