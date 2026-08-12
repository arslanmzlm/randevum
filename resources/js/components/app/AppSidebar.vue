<script setup lang="ts">
import { IconLogout } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import SidebarNav from '@/components/app/SidebarNav.vue';
import type { SharedClinic } from '@/types/clinic';
import type { NavGroup, NavItem } from '@/types/nav';

const props = defineProps<{
    collapsed: boolean;
    clinic: SharedClinic | null;
    navItems: NavItem[];
    navGroups: NavGroup[];
    bottomNavItems: NavItem[];
}>();

const emit = defineEmits<{
    navigate: [];
    logout: [];
}>();

const { t } = useI18n();
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-surface-0">
        <div
            class="flex h-16 shrink-0 items-center gap-3"
            :class="collapsed ? 'justify-center px-2' : 'px-6'"
        >
            <!-- Collapsed uses the icon variant, expanded the wordmark; both fall back to the base
                 logo server-side, so a clinic that uploaded only one image still shows it. -->
            <img
                v-if="collapsed && clinic?.logo_icon_url"
                :src="clinic.logo_icon_url"
                :alt="clinic.name"
                class="size-9 shrink-0 rounded-lg object-cover"
            />
            <template v-else-if="!collapsed">
                <img
                    v-if="clinic?.logo_url"
                    :src="clinic.logo_url"
                    :alt="clinic.name"
                    class="size-9 shrink-0 rounded-lg object-cover dark:hidden"
                />
                <img
                    v-if="clinic?.logo_dark_url"
                    :src="clinic.logo_dark_url"
                    :alt="clinic.name"
                    class="hidden size-9 shrink-0 rounded-lg object-cover dark:block"
                />
            </template>
            <span
                v-if="!collapsed"
                class="truncate text-lg font-semibold"
                :class="clinic ? 'text-surface-900' : 'text-brand'"
            >
                {{ clinic?.name ?? t('auth.layout.brand') }}
            </span>
        </div>

        <div class="flex-1 overflow-y-auto px-3 py-4">
            <SidebarNav
                :items="navItems"
                :groups="navGroups"
                :collapsed="collapsed"
                @navigate="emit('navigate')"
            />
        </div>

        <div class="shrink-0 border-t border-surface-200 p-3">
            <SidebarNav
                :items="bottomNavItems"
                :collapsed="collapsed"
                @navigate="emit('navigate')"
            />

            <button
                v-tooltip.right="collapsed ? t('auth.logout') : undefined"
                type="button"
                class="flex w-full cursor-pointer items-center rounded-2xl py-3 text-sm font-medium text-surface-500 transition-colors hover:bg-surface-100 hover:text-surface-700"
                :class="collapsed ? 'justify-center px-0' : 'gap-3 px-4'"
                :aria-label="collapsed ? t('auth.logout') : undefined"
                @click="emit('logout')"
            >
                <IconLogout class="size-5 shrink-0" />
                <span v-if="!props.collapsed">{{ t('auth.logout') }}</span>
            </button>
        </div>
    </div>
</template>
