<script setup lang="ts">
// Masked TR phone field. v-model is the bare national number (10 digits, e.g. "5321234567")
// thanks to `unmask`; the backend normalizes it to E.164. Keep the mask defined here only,
// so a future format change is a single edit. `inputId`/`invalid` are injected by FormField.
const model = defineModel<string>({ default: '' });

defineProps<{
    inputId?: string;
    invalid?: boolean;
}>();
</script>

<template>
    <InputMask
        :id="inputId"
        v-model="model"
        :invalid="invalid"
        mask="(999) 999 99 99"
        unmask
        type="tel"
        fluid
    />
</template>
