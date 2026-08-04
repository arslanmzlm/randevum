<script setup lang="ts">
// A label + description on the left and a control (ToggleSwitch, Select, …) on the right, in a
// bordered row. The single home for the repeated "setting line" inside preference/settings cards.
defineProps<{
    label: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <span class="text-sm font-medium text-surface-900">{{
                label
            }}</span>
            <span v-if="description" class="text-xs text-surface-500">{{
                description
            }}</span>
        </div>
        <!-- shrink-0: a label wrapping to two lines was squeezing the control out of shape. -->
        <div class="shrink-0">
            <slot />
        </div>
    </div>
</template>
