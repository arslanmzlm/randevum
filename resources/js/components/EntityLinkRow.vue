<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconChevronRight } from '@tabler/icons-vue';
import { computed } from 'vue';

// A bordered row representing one entity (treatment/appointment/case/…) in a detail-page list.
// Renders as an Inertia <Link> (with hover affordance + chevron) when `href` is set, else a plain
// row. `#status` sits next to the title, `#meta`/`meta` is the secondary line, `#trailing` is a
// right-aligned extra (e.g. amount).
const props = defineProps<{
    href?: string;
    title: string;
    meta?: string;
    subMeta?: string;
}>();

const linkProps = computed(() => (props.href ? { href: props.href } : {}));
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        v-bind="linkProps"
        class="flex items-center gap-3 rounded-xl border border-surface-200 p-3"
        :class="
            href
                ? 'transition-colors hover:border-primary-300 hover:bg-surface-50'
                : ''
        "
    >
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex items-center gap-2">
                <span class="truncate text-sm font-medium text-surface-900">{{
                    title
                }}</span>
                <slot name="status" />
            </div>
            <span v-if="meta || $slots.meta" class="text-xs text-surface-500">
                <slot name="meta">{{ meta }}</slot>
            </span>
            <span v-if="subMeta" class="truncate text-xs text-surface-400">{{
                subMeta
            }}</span>
        </div>
        <slot name="trailing" />
        <IconChevronRight
            v-if="href"
            class="size-4 shrink-0 text-surface-400"
        />
    </component>
</template>
