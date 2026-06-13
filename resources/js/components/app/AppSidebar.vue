<script setup lang="ts">
import { IconLogout } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SidebarNav from '@/components/app/SidebarNav.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import type { SharedClinic } from '@/types/clinic';
import type { NavItem } from '@/types/nav';
import type { PatientSearchResult } from '@/types/patient';

const props = defineProps<{
    collapsed: boolean;
    clinic: SharedClinic | null;
    navItems: NavItem[];
    bottomNavItems: NavItem[];
    canViewPatients: boolean;
}>();

const emit = defineEmits<{
    navigate: [];
    selectPatient: [patient: PatientSearchResult];
    logout: [];
}>();

const { t } = useI18n();

const patientSearch = ref<{ focus: () => void } | null>(null);

// Lets AppLayout's Ctrl/Cmd+K shortcut focus the sidebar search.
function focusSearch(): void {
    patientSearch.value?.focus();
}

defineExpose({ focusSearch });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-surface-0">
        <div
            class="flex h-16 shrink-0 items-center gap-3"
            :class="props.collapsed ? 'justify-center px-2' : 'px-6'"
        >
            <img
                v-if="clinic?.logo_url"
                :src="clinic.logo_url"
                :alt="clinic.name"
                class="size-9 shrink-0 rounded-lg object-cover"
            />
            <span
                v-if="!props.collapsed"
                class="truncate text-lg font-semibold"
                :class="clinic ? 'text-surface-900' : 'text-brand'"
            >
                {{ clinic?.name ?? t('auth.layout.brand') }}
            </span>
        </div>

        <div v-if="canViewPatients && !props.collapsed" class="px-3 pb-2">
            <PatientSearchSelect
                ref="patientSearch"
                class="w-full"
                @select="emit('selectPatient', $event)"
            />
        </div>

        <div class="flex-1 overflow-y-auto px-3 py-4">
            <SidebarNav
                :items="navItems"
                :collapsed="props.collapsed"
                @navigate="emit('navigate')"
            />
        </div>

        <div class="shrink-0 border-t border-surface-200 p-3">
            <SidebarNav
                :items="bottomNavItems"
                :collapsed="props.collapsed"
                @navigate="emit('navigate')"
            />

            <button
                v-tooltip.right="props.collapsed ? t('auth.logout') : undefined"
                type="button"
                class="flex w-full cursor-pointer items-center rounded-2xl py-3 text-sm font-medium text-surface-500 transition-colors hover:bg-surface-100 hover:text-surface-700"
                :class="props.collapsed ? 'justify-center px-0' : 'gap-3 px-4'"
                :aria-label="props.collapsed ? t('auth.logout') : undefined"
                @click="emit('logout')"
            >
                <IconLogout class="size-5 shrink-0" />
                <span v-if="!props.collapsed">{{ t('auth.logout') }}</span>
            </button>
        </div>
    </div>
</template>
