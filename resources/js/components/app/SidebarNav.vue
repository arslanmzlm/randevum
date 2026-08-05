<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconChevronDown } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import SidebarNavItem from '@/components/app/SidebarNavItem.vue';
import type { NavGroup, NavItem } from '@/types/nav';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        collapsed: boolean;
        groups?: NavGroup[];
    }>(),
    { groups: () => [] },
);

const emit = defineEmits<{
    navigate: [];
}>();

const page = usePage();

const allItems = computed<NavItem[]>(() => [
    ...props.items,
    ...props.groups.flatMap((group) => group.items),
]);

const currentPath = computed(() => page.url.split('?')[0]);

/**
 * The longest nav path the current page sits under. Without it, /appointments/create would light
 * up "Randevular" (and every other entry sharing that path) alongside "Randevu oluştur"; only the
 * most specific entry should read as active.
 */
const deepestMatch = computed<string | null>(() => {
    const paths = allItems.value
        .map((item) => item.href.split('?')[0])
        .filter(
            (path) =>
                currentPath.value === path ||
                currentPath.value.startsWith(`${path}/`),
        )
        .sort((a, b) => b.length - a.length);

    return paths[0] ?? null;
});

/** True while a filtered entry (…?filter[status]=no_show) owns the current URL exactly. */
const filteredEntryActive = computed(() =>
    allItems.value.some(
        (item) => item.href.includes('?') && item.href === page.url,
    ),
);

function isActive(href: string): boolean {
    // A filtered entry means one specific view, not a section: it lights up on its own URL only.
    if (href.includes('?')) {
        return page.url === href;
    }

    // Otherwise the section entry wins, unless a filtered sibling is the page being viewed
    // (…/appointments vs …/appointments?filter[status]=no_show).
    return href === deepestMatch.value && !filteredEntryActive.value;
}

function holdsActive(group: NavGroup): boolean {
    return group.items.some((item) => isActive(item.href));
}

// Groups start closed; the one holding the current page opens itself, and a manual toggle sticks
// until navigation lands somewhere else.
const openKeys = ref<Set<string>>(
    new Set(props.groups.filter(holdsActive).map((group) => group.key)),
);

watch(
    () => page.url,
    () => {
        props.groups.filter(holdsActive).forEach((group) => {
            openKeys.value.add(group.key);
        });
    },
);

function toggle(group: NavGroup): void {
    if (openKeys.value.has(group.key)) {
        openKeys.value.delete(group.key);

        return;
    }

    openKeys.value.add(group.key);
}

// The icon rail has no room for group headers, so it shows every destination flat.
const railItems = computed<NavItem[]>(() => [
    ...props.items,
    ...props.groups.flatMap((group) => group.items),
]);
</script>

<template>
    <nav class="flex flex-col gap-1">
        <template v-if="collapsed">
            <SidebarNavItem
                v-for="item in railItems"
                :key="item.href"
                :item="item"
                collapsed
                :active="isActive(item.href)"
                @navigate="emit('navigate')"
            />
        </template>

        <template v-else>
            <SidebarNavItem
                v-for="item in items"
                :key="item.href"
                :item="item"
                :collapsed="false"
                :active="isActive(item.href)"
                @navigate="emit('navigate')"
            />

            <div
                v-for="group in groups"
                :key="group.key"
                class="mt-2 flex flex-col gap-1"
            >
                <button
                    type="button"
                    class="flex cursor-pointer items-center gap-3 rounded-2xl px-4 py-2 text-xs font-semibold tracking-wide text-surface-400 uppercase transition-colors hover:text-surface-600"
                    :aria-expanded="openKeys.has(group.key)"
                    @click="toggle(group)"
                >
                    <component :is="group.icon" class="size-4 shrink-0" />
                    <span class="truncate">{{ group.label }}</span>
                    <IconChevronDown
                        class="ml-auto size-4 shrink-0 transition-transform"
                        :class="openKeys.has(group.key) ? 'rotate-180' : ''"
                    />
                </button>

                <SidebarNavItem
                    v-for="item in group.items"
                    v-show="openKeys.has(group.key)"
                    :key="item.href"
                    :item="item"
                    :collapsed="false"
                    :active="isActive(item.href)"
                    @navigate="emit('navigate')"
                />
            </div>
        </template>
    </nav>
</template>
