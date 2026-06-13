<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { NavItem } from '@/types/nav';

const props = defineProps<{
    item: NavItem;
    collapsed: boolean;
    active: boolean;
}>();

const emit = defineEmits<{
    navigate: [];
}>();

// Single source of truth for the nav link treatment — reused by both the
// full sidebar and the rail so the active/hover styling never drifts.
const linkClass = computed(() => [
    'flex items-center rounded-2xl py-3 text-sm font-medium transition-colors',
    props.collapsed ? 'justify-center px-0' : 'gap-3 px-4',
    props.active
        ? 'bg-primary-50 text-primary-700'
        : 'text-surface-500 hover:bg-primary-50 hover:text-primary-700',
]);
</script>

<template>
    <Link
        v-tooltip.right="collapsed ? item.label : undefined"
        :href="item.href"
        :class="linkClass"
        :aria-label="collapsed ? item.label : undefined"
        @click="emit('navigate')"
    >
        <component :is="item.icon" class="size-5 shrink-0" />
        <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
    </Link>
</template>
