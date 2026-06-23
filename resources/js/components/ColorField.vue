<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    label: string;
    error?: string;
    hint?: string;
    /** Quick-pick swatches, each a '#RRGGBB' string. */
    presets: readonly string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

// Coerce any input to a canonical '#RRGGBB' (uppercase) so the stored value and the
// backend's `/^#[0-9A-Fa-f]{6}$/` rule always agree, regardless of paste/picker source.
function normalize(hex: string): string {
    return `#${hex
        .replace(/[^0-9a-fA-F]/g, '')
        .slice(0, 6)
        .toUpperCase()}`;
}

// PrimeVue ColorPicker (format="hex") models a bare hex with no leading '#';
// strip it on the way in and re-add it on the way out.
const pickerValue = computed({
    get: () => props.modelValue.replace(/^#/, ''),
    set: (hex: string) => emit('update:modelValue', normalize(hex)),
});

const hexValue = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', normalize(value)),
});

function isSelected(color: string): boolean {
    return props.modelValue.toUpperCase() === color.toUpperCase();
}

function selectPreset(color: string): void {
    emit('update:modelValue', normalize(color));
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <span class="text-sm font-medium text-surface-700">{{ label }}</span>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="color in presets"
                :key="color"
                type="button"
                class="flex size-7 cursor-pointer items-center justify-center rounded-full ring-offset-2 ring-offset-surface-0 transition-shadow"
                :class="
                    isSelected(color)
                        ? 'ring-2 ring-surface-400'
                        : 'hover:ring-2 hover:ring-surface-200'
                "
                :style="{ backgroundColor: color }"
                :aria-label="color"
                :aria-pressed="isSelected(color)"
                @click="selectPreset(color)"
            >
                <IconCheck
                    v-if="isSelected(color)"
                    class="size-4 text-white"
                    stroke="3"
                />
            </button>
        </div>

        <div class="flex items-center gap-2">
            <ColorPicker v-model="pickerValue" format="hex" />
            <InputText
                v-model="hexValue"
                :invalid="!!error"
                class="w-32 font-mono uppercase"
                maxlength="7"
            />
        </div>

        <small v-if="error" class="text-xs text-red-500">{{ error }}</small>
        <small v-else-if="hint" class="text-xs text-surface-400">{{
            hint
        }}</small>
    </div>
</template>
