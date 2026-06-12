<script setup lang="ts">
const props = defineProps<{
    options: Array<{ label: string; value: number; color: string }>;
    /** Declared so FormField's cloneVNode injection reaches the inner Select. */
    inputId?: string;
    invalid?: boolean;
}>();

const model = defineModel<number | null>({ required: true });

// PrimeVue Select's #value slot hands back the raw value, not the option, so resolve
// the chosen type's label/color from the option list to render the colored swatch.
function colorFor(value: number): string | undefined {
    return props.options.find((o) => o.value === value)?.color;
}

function labelFor(value: number): string | undefined {
    return props.options.find((o) => o.value === value)?.label;
}
</script>

<template>
    <Select
        v-model="model"
        :options="options"
        option-label="label"
        option-value="value"
        :input-id="props.inputId"
        :invalid="props.invalid"
        show-clear
        fluid
    >
        <template #value="{ value }">
            <span
                v-if="value !== null && value !== undefined"
                class="flex items-center gap-2"
            >
                <span
                    class="size-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: colorFor(value) }"
                    :aria-hidden="true"
                />
                {{ labelFor(value) }}
            </span>
            <!-- FloatLabel passes no placeholder; a non-breaking space keeps the empty label
                 the same height as a selected value (variant="in" reserves the floated-label
                 row), matching the sibling selects. -->
            <span v-else>&nbsp;</span>
        </template>
        <template #option="{ option }">
            <span class="flex items-center gap-2">
                <span
                    class="size-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: option.color }"
                    :aria-hidden="true"
                />
                {{ option.label }}
            </span>
        </template>
    </Select>
</template>
