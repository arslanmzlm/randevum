<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        /** '#RRGGBB' background. */
        color: string;
        /** Shows a remove affordance and enables the `remove` emit. */
        removable?: boolean;
    }>(),
    { removable: false },
);

const emit = defineEmits<{ remove: [] }>();

// Pick a readable foreground from the tag colour's relative luminance so light
// and dark swatches both stay legible (WCAG-style 0.6 split), instead of a fixed white.
const textColor = computed(() => {
    const hex = props.color.replace(/^#/, '');

    if (hex.length !== 6) {
        return '#FFFFFF';
    }

    const channel = (start: number): number => {
        const v = parseInt(hex.slice(start, start + 2), 16) / 255;

        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };

    const luminance =
        0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);

    return luminance > 0.6 ? '#1E293B' : '#FFFFFF';
});
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
        :style="{ backgroundColor: color, color: textColor }"
    >
        <span class="truncate">{{ label }}</span>
        <button
            v-if="removable"
            type="button"
            class="-mr-0.5 flex shrink-0 cursor-pointer items-center rounded-full transition-opacity hover:opacity-70"
            :style="{ color: textColor }"
            :aria-label="label"
            @click.stop="emit('remove')"
        >
            <IconX class="size-3" stroke="3" />
        </button>
    </span>
</template>
