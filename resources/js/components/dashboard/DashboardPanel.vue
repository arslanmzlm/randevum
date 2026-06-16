<script setup lang="ts">
// Shared shell for every dashboard card so they match exactly: rounded-2xl, surface-0 body,
// surface-200 border, a header (title slot + optional actions slot) and a bordered body
// (+ optional footer). Keeps StatCard's surface tokens; replaces the old per-widget shells
// (PrimeVue Card / surface-50 panel) that drifted on bg, border and spacing.
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-2xl border border-surface-200 bg-surface-0"
    >
        <header class="flex items-center justify-between gap-2 px-5 py-4">
            <div class="flex min-w-0 items-center gap-2">
                <slot name="title" />
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div class="border-t border-surface-200">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t border-surface-200 px-5 py-3"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
