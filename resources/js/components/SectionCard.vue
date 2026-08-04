<script setup lang="ts">
import type { Component } from 'vue';

// The single card surface for the app: rounded-xl, surface-0 body, surface-200 border.
// Two layouts:
//  - 'card' (default): a padded form/detail card; header sits inside the padding with mb-6.
//  - 'divided': dashboard-style panel; header/body/footer split by border-t, body unpadded
//    (the body content — DataTable, list — carries its own spacing). DashboardPanel feeds off this.
// Title can come from `title`+`icon` props (standard icon + heading) or the `#title` slot for
// custom content. Extra layout classes (lg:col-span-2, max-w-xl) pass through onto the root.
withDefaults(
    defineProps<{
        title?: string;
        icon?: Component;
        variant?: 'card' | 'divided';
        padding?: string;
    }>(),
    {
        title: undefined,
        icon: undefined,
        variant: 'card',
        padding: 'p-6 sm:p-8',
    },
);
</script>

<template>
    <section
        v-if="variant === 'divided'"
        class="flex flex-col overflow-hidden rounded-xl border border-surface-200 bg-surface-0"
    >
        <header
            v-if="$slots.title || title || $slots.actions"
            class="flex items-center justify-between gap-2 px-5 py-4"
        >
            <div class="flex min-w-0 items-center gap-2">
                <slot name="title">
                    <component
                        :is="icon"
                        v-if="icon"
                        class="size-5 text-surface-500"
                    />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ title }}
                    </h2>
                </slot>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div class="section-card-body border-t border-surface-200">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t border-surface-200 px-5 py-3"
        >
            <slot name="footer" />
        </footer>
    </section>

    <section
        v-else
        class="rounded-xl border border-surface-200 bg-surface-0"
        :class="padding"
    >
        <header
            v-if="$slots.title || title || $slots.actions"
            class="mb-6 flex items-center justify-between gap-2"
        >
            <div class="flex min-w-0 items-center gap-2">
                <slot name="title">
                    <component
                        :is="icon"
                        v-if="icon"
                        class="size-5 text-surface-500"
                    />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ title }}
                    </h2>
                </slot>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <slot />

        <footer v-if="$slots.footer" class="mt-6">
            <slot name="footer" />
        </footer>
    </section>
</template>

<style scoped>
/* A DataTable's last row draws its own bottom border, which lands directly on the card border (or
   the footer's border-t) and reads as a double line. The card owns the dividing lines here. */
.section-card-body :deep(.p-datatable-tbody > tr:last-child > td) {
    border-bottom-width: 0;
}
</style>
