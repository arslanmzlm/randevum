<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';

// Shared no-results / empty placeholder. `panel` = full-section empty (rounded card surface);
// `dashed` = nested sub-section empty inside an already-carded detail panel. Caller supplies the
// Tabler icon component and the (already-translated) strings; an optional #action slot holds a CTA.
const props = withDefaults(
    defineProps<{
        icon?: Component;
        message: string;
        description?: string;
        variant?: 'panel' | 'dashed';
        bordered?: boolean;
    }>(),
    { variant: 'panel', bordered: true },
);

const containerClass = computed(() =>
    props.variant === 'dashed'
        ? 'gap-2 rounded-lg border border-dashed border-surface-200 px-4 py-10'
        : [
              'gap-3 px-6 py-16',
              props.bordered
                  ? 'rounded-xl border border-surface-200 bg-surface-0'
                  : '',
          ],
);

const iconClass = computed(() =>
    props.variant === 'dashed'
        ? 'size-8 text-surface-300'
        : 'size-10 text-surface-300',
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center text-center"
        :class="containerClass"
    >
        <component :is="icon" v-if="icon" :class="iconClass" />
        <p class="text-sm text-surface-500">{{ message }}</p>
        <p v-if="description" class="text-xs text-surface-400">
            {{ description }}
        </p>
        <slot name="action" />
    </div>
</template>
