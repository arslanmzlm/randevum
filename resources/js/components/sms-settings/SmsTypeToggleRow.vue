<script setup lang="ts">
defineProps<{
    label: string;
    hint?: string;
    disabled?: boolean;
}>();

const model = defineModel<boolean>({ required: true });
</script>

<template>
    <div
        class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <span class="text-sm font-medium text-surface-900">{{
                label
            }}</span>
            <span v-if="hint" class="text-xs text-surface-500">{{ hint }}</span>
        </div>
        <ToggleSwitch v-model="model" :disabled="disabled" />
    </div>
</template>
