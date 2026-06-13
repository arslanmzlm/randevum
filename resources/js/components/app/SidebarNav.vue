<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import SidebarNavItem from '@/components/app/SidebarNavItem.vue';
import type { NavItem } from '@/types/nav';

defineProps<{
    items: NavItem[];
    collapsed: boolean;
}>();

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();

function isActive(href: string): boolean {
    return page.url === href || page.url.startsWith(`${href}/`);
}
</script>

<template>
    <nav class="flex flex-col gap-1">
        <SidebarNavItem
            v-for="item in items"
            :key="item.href"
            :item="item"
            :collapsed="collapsed"
            :active="isActive(item.href)"
            @navigate="emit('navigate')"
        />
    </nav>
</template>
