<script setup lang="ts">
/**
 * The stock figure with its one severity rule: negative stock is a `danger` Tag, anything
 * else is plain text. Both the product list and the movements page render it through here so
 * the same number can't look different on two screens.
 */
defineProps<{
    stock: number;
    unit: string;
}>();
</script>

<template>
    <Tag v-if="stock < 0" severity="danger" :value="`${stock} ${unit}`" />
    <span v-else class="text-surface-700">{{ stock }} {{ unit }}</span>
</template>
