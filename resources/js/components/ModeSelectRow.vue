<script setup lang="ts" generic="T extends string">
// Inline radio-group row: a horizontal set of "pick one mode" options (payment / case-link /
// follow-up sections share this exact shape). Generic over the mode union so v-model stays typed.
withDefaults(
    defineProps<{
        options: ReadonlyArray<{ value: T; label: string }>;
        idPrefix: string;
        gap?: string;
    }>(),
    { gap: 'gap-4' },
);

const model = defineModel<T>({ required: true });
</script>

<template>
    <div class="flex flex-wrap" :class="gap">
        <div
            v-for="option in options"
            :key="option.value"
            class="flex items-center gap-2"
        >
            <RadioButton
                v-model="model"
                :input-id="`${idPrefix}-${option.value}`"
                :value="option.value"
            />
            <label
                :for="`${idPrefix}-${option.value}`"
                class="cursor-pointer text-sm text-surface-700"
            >
                {{ option.label }}
            </label>
        </div>
    </div>
</template>
