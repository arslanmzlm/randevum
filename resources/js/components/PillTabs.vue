<script setup lang="ts">
import type { Component } from 'vue';

/**
 * The app's tab strip: pill-shaped buttons with an icon, and panels that add no surface of their
 * own so the cards inside keep standing on the page background (the default PrimeVue panel paints
 * a white block behind them and makes every card look nested).
 *
 * `id` is per-tab so callers can keep a stable handle for deep links and tests.
 */
defineProps<{
    tabs: { value: string; label: string; icon?: Component; id?: string }[];
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <Tabs v-model:value="model" class="pill-tabs">
        <TabList>
            <Tab
                v-for="tab in tabs"
                :id="tab.id"
                :key="tab.value"
                :value="tab.value"
            >
                <span class="flex items-center gap-2">
                    <component :is="tab.icon" v-if="tab.icon" class="size-4" />
                    {{ tab.label }}
                </span>
            </Tab>
        </TabList>

        <TabPanels>
            <slot />
        </TabPanels>
    </Tabs>
</template>

<style scoped>
/* PrimeVue's underline treatment turned into pills. Colors stay on design tokens so dark mode
   follows along; only geometry is hard-coded here. */

/* The strip itself is not a surface: pills sit directly on the page. */
.pill-tabs,
.pill-tabs :deep(.p-tablist),
.pill-tabs :deep(.p-tablist-content),
.pill-tabs :deep(.p-tablist-viewport) {
    border: 0;
    background: transparent;
}

.pill-tabs :deep(.p-tablist-tab-list) {
    display: flex;
    gap: 0.5rem;
    border-bottom: 0;
    background: transparent;
}

.pill-tabs :deep(.p-tab) {
    border: 1px solid var(--p-surface-200);
    border-radius: 9999px;
    padding: 0.5rem 1rem;
    margin: 0;
    background: var(--p-surface-0);
    color: var(--p-surface-600);
}

.pill-tabs :deep(.p-tab:hover) {
    background: var(--p-surface-100);
    color: var(--p-surface-800);
}

.pill-tabs :deep(.p-tab-active),
.pill-tabs :deep(.p-tab-active:hover) {
    border-color: var(--p-primary-500);
    background: var(--p-primary-500);
    color: var(--p-primary-contrast-color);
}

/* The sliding underline has no place under pills. */
.pill-tabs :deep(.p-tablist-active-bar) {
    display: none;
}

/* Panels carry no surface: the cards inside own their own background. */
.pill-tabs :deep(.p-tabpanels) {
    padding: 1.5rem 0 0;
    background: transparent;
}
</style>
