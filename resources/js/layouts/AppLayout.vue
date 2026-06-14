<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconBuildingHospital,
    IconCalendarOff,
    IconCalendarPlus,
    IconCalendarWeek,
    IconClipboardList,
    IconFolders,
    IconHome,
    IconListDetails,
    IconLogout,
    IconMessage,
    IconPackage,
    IconSettings,
    IconStethoscope,
    IconTags,
    IconUserCircle,
    IconUsers,
} from '@tabler/icons-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watchEffect,
} from 'vue';
import { useI18n } from 'vue-i18n';
import AppSidebar from '@/components/app/AppSidebar.vue';
import QuickAccessSidebar from '@/components/app/QuickAccessSidebar.vue';
import QuickAccessToggle from '@/components/app/QuickAccessToggle.vue';
import SidebarToggle from '@/components/app/SidebarToggle.vue';
import AppToaster from '@/components/AppToaster.vue';
import { useCan } from '@/composables/useCan';
import { useContentWidth } from '@/composables/useContentWidth';
import { useQuickAccess } from '@/composables/useQuickAccess';
import { useSidebar } from '@/composables/useSidebar';
import { account, dashboard, logout } from '@/routes';
import { index as appointmentTypesIndex } from '@/routes/appointment-types';
import {
    create as appointmentCreate,
    index as appointmentsIndex,
} from '@/routes/appointments';
import { index as calendarIndex } from '@/routes/calendar';
import { index as casesIndex } from '@/routes/cases';
import { edit as clinicEdit } from '@/routes/clinic';
import { edit as smsSettingsEdit } from '@/routes/clinic/sms-settings';
import { index as doctorsIndex, mine as doctorsMine } from '@/routes/doctors';
import { index as patientsIndex, show as patientShow } from '@/routes/patients';
import { index as productsIndex } from '@/routes/products';
import { index as availabilityIndex } from '@/routes/schedule-exceptions';
import { index as servicesIndex } from '@/routes/services';
import type { NavItem } from '@/types/nav';
import type { PatientSearchResult } from '@/types/patient';

// Per-page desktop sidebar intent, set by pages via `setLayoutProps({ sidebar })`.
const props = withDefaults(
    defineProps<{ sidebar?: 'default' | 'collapsed' | 'hidden' }>(),
    { sidebar: 'default' },
);

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const isDoctor = computed(() => page.props.auth?.isDoctor === true);
const { can } = useCan();
// Reused in the template (sidebar search) + the Ctrl/Cmd+K handler, so kept as a computed.
const canViewPatients = computed(() => can('patients.viewAny'));
const canViewUpcoming = computed(() => can('appointments.viewAny'));
const clinic = computed(() => page.props.activeClinic ?? null);
const { width: contentWidth, toggle: toggleWidth } = useContentWidth();
const { collapsed, mobileOpen, closeMobile, expand, setDesktopHidden } =
    useSidebar();
const {
    desktopOpen: quickOpen,
    mobileOpen: quickMobileOpen,
    closeMobile: closeQuickMobile,
} = useQuickAccess();

// 'hidden' page → no desktop sidebar; 'collapsed' → forced rail; otherwise the
// user's stored preference. The drawer covers the off-canvas case in every mode.
const desktopSidebarHidden = computed(() => props.sidebar === 'hidden');
const railCollapsed = computed(() =>
    props.sidebar === 'collapsed' ? true : collapsed.value,
);

// Keep the composable in step so the toggle drives the drawer (not the rail) on
// a `hidden` page even at desktop widths.
watchEffect(() => setDesktopHidden(desktopSidebarHidden.value));

const userName = computed(() => {
    const u = user.value;

    if (!u) {
        return '';
    }

    return (
        [u.first_name, u.last_name].filter(Boolean).join(' ') || u.email || ''
    );
});

// Only routes that already exist, gated by capability (a doctor sees neither the
// clinic profile nor the doctors management entry). Features add their own as they land.
const navItems = computed<NavItem[]>(() => [
    { label: t('nav.dashboard'), href: dashboard().url, icon: IconHome },
    ...(can('appointments.viewAny')
        ? [
              {
                  label: t('nav.calendar'),
                  href: calendarIndex().url,
                  icon: IconCalendarWeek,
              },
          ]
        : []),
    ...(can('appointments.viewAny')
        ? [
              {
                  label: t('nav.appointments'),
                  href: appointmentsIndex().url,
                  icon: IconListDetails,
              },
          ]
        : []),
    ...(can('appointments.create')
        ? [
              {
                  label: t('nav.appointments_create'),
                  href: appointmentCreate().url,
                  icon: IconCalendarPlus,
              },
          ]
        : []),
    ...(can('clinic.update')
        ? [
              {
                  label: t('nav.clinic'),
                  href: clinicEdit().url,
                  icon: IconBuildingHospital,
              },
          ]
        : []),
    ...(can('doctors.create')
        ? [
              {
                  label: t('nav.doctors'),
                  href: doctorsIndex().url,
                  icon: IconStethoscope,
              },
          ]
        : []),
    ...(canViewPatients.value
        ? [
              {
                  label: t('nav.patients'),
                  href: patientsIndex().url,
                  icon: IconUsers,
              },
          ]
        : []),
    ...(can('cases.viewAny')
        ? [
              {
                  label: t('nav.cases'),
                  href: casesIndex().url,
                  icon: IconFolders,
              },
          ]
        : []),
    ...(can('services.viewAny')
        ? [
              {
                  label: t('nav.services'),
                  href: servicesIndex().url,
                  icon: IconClipboardList,
              },
          ]
        : []),
    ...(can('products.viewAny')
        ? [
              {
                  label: t('nav.products'),
                  href: productsIndex().url,
                  icon: IconPackage,
              },
          ]
        : []),
    ...(can('appointmentTypes.create')
        ? [
              {
                  label: t('nav.appointment_types'),
                  href: appointmentTypesIndex().url,
                  icon: IconTags,
              },
          ]
        : []),
    ...(can('scheduleExceptions.viewAny')
        ? [
              {
                  label: t('nav.availability'),
                  href: availabilityIndex().url,
                  icon: IconCalendarOff,
              },
          ]
        : []),
    ...(can('smsSettings.view')
        ? [
              {
                  label: t('nav.sms_settings'),
                  href: smsSettingsEdit().url,
                  icon: IconMessage,
              },
          ]
        : []),
]);

// Account/secondary items pinned to the bottom, above logout.
const bottomNavItems = computed<NavItem[]>(() => [
    { label: t('nav.account'), href: account().url, icon: IconUserCircle },
]);

const userMenu = ref();
// `tablerIcon` (not `icon`) because PrimeVue's MenuItem.icon is a string
// (PrimeIcon class); we render a Tabler component in the #item slot instead.
const userMenuItems = computed(() => [
    ...(isDoctor.value
        ? [
              {
                  label: t('nav.profile_mine'),
                  tablerIcon: IconUserCircle,
                  command: () => router.visit(doctorsMine().url),
              },
          ]
        : []),
    {
        label: t('nav.account'),
        tablerIcon: IconSettings,
        command: () => router.visit(account().url),
    },
    { separator: true },
    {
        label: t('auth.logout'),
        tablerIcon: IconLogout,
        command: () => doLogout(),
    },
]);

function toggleUserMenu(event: Event): void {
    userMenu.value?.toggle(event);
}

function doLogout(): void {
    router.post(logout().url);
}

function goToPatient(patient: PatientSearchResult): void {
    closeMobile();
    router.visit(patientShow(patient.id).url);
}

const desktopSidebar = ref<{ focusSearch: () => void } | null>(null);

// Ctrl/Cmd+K reveals the rail (if collapsed) then focuses the sidebar search.
function onSearchShortcut(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
        return;
    }

    if (!canViewPatients.value || desktopSidebarHidden.value) {
        return;
    }

    event.preventDefault();
    expand();
    nextTick(() => desktopSidebar.value?.focusSearch());
}

// One-time, dismissible nudge to change the admin-set password on first-ever
// login (driven off the shared `password_reminder` flash).
const showPasswordReminder = ref(false);

// Close the off-canvas quick-access drawer once a navigation lands (its inner links
// would otherwise leave it open on top of the new page).
let stopNavListener: (() => void) | undefined;

onMounted(() => {
    const flash = page.props.flash as
        | { password_reminder?: boolean }
        | undefined;

    if (flash?.password_reminder) {
        showPasswordReminder.value = true;
    }

    window.addEventListener('keydown', onSearchShortcut);
    stopNavListener = router.on('navigate', () => closeQuickMobile());
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onSearchShortcut);
    stopNavListener?.();
});

function goToPasswordChange(): void {
    showPasswordReminder.value = false;
    router.visit(account().url);
}
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-surface-50">
        <AppToaster />
        <ConfirmDialog />

        <Dialog
            v-model:visible="showPasswordReminder"
            modal
            dismissable-mask
            :draggable="false"
            :header="t('password_reminder.title')"
            class="w-full max-w-md"
        >
            <p class="text-sm text-surface-600">
                {{ t('password_reminder.message') }}
            </p>
            <template #footer>
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('password_reminder.later')"
                    @click="showPasswordReminder = false"
                />
                <Button
                    type="button"
                    :label="t('password_reminder.change')"
                    @click="goToPasswordChange"
                />
            </template>
        </Dialog>

        <Drawer
            v-model:visible="mobileOpen"
            position="left"
            class="w-72"
            :pt="{ content: { class: 'p-0' }, header: { class: 'hidden' } }"
        >
            <AppSidebar
                :collapsed="false"
                :clinic="clinic"
                :nav-items="navItems"
                :bottom-nav-items="bottomNavItems"
                :can-view-patients="canViewPatients"
                @navigate="closeMobile"
                @select-patient="goToPatient"
                @logout="doLogout"
            />
        </Drawer>

        <Drawer
            v-if="canViewUpcoming"
            v-model:visible="quickMobileOpen"
            position="right"
            class="w-80"
            :pt="{ content: { class: 'p-0' }, header: { class: 'hidden' } }"
        >
            <QuickAccessSidebar />
        </Drawer>

        <aside
            v-if="!desktopSidebarHidden"
            class="hidden shrink-0 border-r border-surface-200 transition-[width] lg:flex"
            :class="railCollapsed ? 'lg:w-18' : 'lg:w-64'"
        >
            <AppSidebar
                ref="desktopSidebar"
                class="w-full"
                :collapsed="railCollapsed"
                :clinic="clinic"
                :nav-items="navItems"
                :bottom-nav-items="bottomNavItems"
                :can-view-patients="canViewPatients"
                @select-patient="goToPatient"
                @logout="doLogout"
            />
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header
                class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-surface-200 bg-surface-0 px-6"
            >
                <SidebarToggle />

                <div class="flex shrink-0 items-center gap-1">
                    <QuickAccessToggle v-if="canViewUpcoming" />

                    <Button
                        type="button"
                        severity="secondary"
                        text
                        rounded
                        :aria-label="
                            contentWidth === 'fluid'
                                ? t('app.width.collapse')
                                : t('app.width.expand')
                        "
                        @click="toggleWidth"
                    >
                        <component
                            :is="
                                contentWidth === 'fluid'
                                    ? IconArrowsMinimize
                                    : IconArrowsMaximize
                            "
                            class="size-5"
                        />
                    </Button>

                    <Button
                        type="button"
                        severity="secondary"
                        text
                        class="gap-2"
                        aria-haspopup="true"
                        aria-controls="user-menu"
                        @click="toggleUserMenu"
                    >
                        <IconUserCircle class="size-5 shrink-0" />
                        <span class="max-w-40 truncate">{{ userName }}</span>
                    </Button>

                    <Menu
                        id="user-menu"
                        ref="userMenu"
                        :model="userMenuItems"
                        :popup="true"
                    >
                        <template #item="{ item, props: itemProps }">
                            <a
                                class="flex items-center gap-2"
                                v-bind="itemProps.action"
                            >
                                <component
                                    :is="item.tablerIcon"
                                    class="size-4 shrink-0"
                                />
                                <span>{{ item.label }}</span>
                            </a>
                        </template>
                    </Menu>
                </div>
            </header>

            <div class="flex min-h-0 flex-1 overflow-hidden">
                <main class="flex-1 overflow-y-auto py-6 lg:py-8">
                    <div
                        :class="
                            contentWidth === 'fluid'
                                ? 'container-fluid'
                                : 'container'
                        "
                    >
                        <slot />
                    </div>
                </main>

                <aside
                    v-if="canViewUpcoming && quickOpen"
                    class="hidden shrink-0 border-l border-surface-200 bg-surface-0 xl:flex xl:w-[22rem]"
                >
                    <QuickAccessSidebar class="w-full" />
                </aside>
            </div>
        </div>
    </div>
</template>
