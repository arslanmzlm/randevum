<script setup lang="ts">
import {
    IconLayoutSidebarRightCollapse,
    IconLayoutSidebarRightExpand,
    IconX,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useQuickAccess } from '@/composables/useQuickAccess';

const { t } = useI18n();
const { desktopOpen, mobileOpen, isWide, toggle } = useQuickAccess();

// On a wide screen the toggle flips the docked aside; on a narrow one it drives the drawer.
const icon = computed(() => {
    if (isWide.value) {
        return desktopOpen.value
            ? IconLayoutSidebarRightCollapse
            : IconLayoutSidebarRightExpand;
    }

    return mobileOpen.value ? IconX : IconLayoutSidebarRightExpand;
});

const label = computed(() => {
    if (isWide.value) {
        return desktopOpen.value
            ? t('quick_access.collapse')
            : t('quick_access.expand');
    }

    return mobileOpen.value ? t('quick_access.close') : t('quick_access.open');
});
</script>

<template>
    <Button
        type="button"
        severity="secondary"
        text
        rounded
        :aria-label="label"
        @click="toggle"
    >
        <component :is="icon" class="size-5" />
    </Button>
</template>
