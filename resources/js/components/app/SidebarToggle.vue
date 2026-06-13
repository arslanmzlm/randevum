<script setup lang="ts">
import {
    IconLayoutSidebarLeftCollapse,
    IconLayoutSidebarLeftExpand,
    IconMenu2,
    IconX,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSidebar } from '@/composables/useSidebar';

const { t } = useI18n();
const { collapsed, mobileOpen, isDesktop, desktopHidden, toggle } =
    useSidebar();

// On desktop with a visible sidebar the toggle flips the rail; otherwise
// (mobile, or a `hidden` page) it drives the off-canvas drawer.
const drivesDesktopRail = computed(
    () => isDesktop.value && !desktopHidden.value,
);

const icon = computed(() => {
    if (drivesDesktopRail.value) {
        return collapsed.value
            ? IconLayoutSidebarLeftExpand
            : IconLayoutSidebarLeftCollapse;
    }

    return mobileOpen.value ? IconX : IconMenu2;
});

const label = computed(() => {
    if (drivesDesktopRail.value) {
        return collapsed.value
            ? t('app.sidebar.expand')
            : t('app.sidebar.collapse');
    }

    return mobileOpen.value ? t('app.sidebar.close') : t('app.sidebar.open');
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
