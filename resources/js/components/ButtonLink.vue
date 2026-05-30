<script setup lang="ts">
// Inertia <Link> styled as a PrimeVue Button (forwards all Button props + icon slots).
import { Link } from '@inertiajs/vue3';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <Button :as="Link" v-bind="$attrs">
        <slot />
        <template #icon>
            <slot name="icon" />
        </template>
        <template #loadingicon>
            <slot name="loadingicon" />
        </template>
    </Button>
</template>
