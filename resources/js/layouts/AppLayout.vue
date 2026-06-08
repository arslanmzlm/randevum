<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconBuildingHospital,
    IconCalendarOff,
    IconCalendarPlus,
    IconCalendarWeek,
    IconClipboardList,
    IconHome,
    IconListDetails,
    IconLogout,
    IconPackage,
    IconSettings,
    IconStethoscope,
    IconTags,
    IconUserCircle,
    IconUsers,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppToaster from '@/components/AppToaster.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import { useCan } from '@/composables/useCan';
import { useContentWidth } from '@/composables/useContentWidth';
import { account, dashboard, logout } from '@/routes';
import { index as appointmentTypesIndex } from '@/routes/appointment-types';
import {
    create as appointmentCreate,
    index as appointmentsIndex,
} from '@/routes/appointments';
import { index as calendarIndex } from '@/routes/calendar';
import { edit as clinicEdit } from '@/routes/clinic';
import { index as doctorsIndex, mine as doctorsMine } from '@/routes/doctors';
import { index as patientsIndex, show as patientShow } from '@/routes/patients';
import { index as productsIndex } from '@/routes/products';
import { index as availabilityIndex } from '@/routes/schedule-exceptions';
import { index as servicesIndex } from '@/routes/services';
import type { PatientSearchResult } from '@/types/patient';

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const isDoctor = computed(() => page.props.auth?.isDoctor === true);
const { can } = useCan();
// Reused in the template (sidebar search) + the Ctrl/Cmd+K handler, so kept as a computed.
const canViewPatients = computed(() => can('patients.viewAny'));
const clinic = computed(() => page.props.activeClinic ?? null);
const { width: contentWidth, toggle: toggleWidth } = useContentWidth();
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
const navItems = computed(() => [
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
]);

// Account/secondary items pinned to the bottom, above logout.
const bottomNavItems = computed(() => [
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

function isActive(href: string): boolean {
    return page.url === href || page.url.startsWith(`${href}/`);
}

function linkClass(href: string): string[] {
    return [
        'flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition-colors',
        isActive(href)
            ? 'bg-primary-50 text-primary-700'
            : 'text-surface-500 hover:bg-primary-50 hover:text-primary-700',
    ];
}

function doLogout(): void {
    router.post(logout().url);
}

const patientSearch = ref<{ focus: () => void } | null>(null);

function goToPatient(patient: PatientSearchResult): void {
    router.visit(patientShow(patient.id).url);
}

// Ctrl/Cmd+K focuses the sidebar patient search from anywhere in the shell.
function onSearchShortcut(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
        return;
    }

    if (!canViewPatients.value) {
        return;
    }

    event.preventDefault();
    patientSearch.value?.focus();
}

// One-time, dismissible nudge to change the admin-set password on first-ever
// login (driven off the shared `password_reminder` flash).
const showPasswordReminder = ref(false);

onMounted(() => {
    const flash = page.props.flash as
        | { password_reminder?: boolean }
        | undefined;

    if (flash?.password_reminder) {
        showPasswordReminder.value = true;
    }

    window.addEventListener('keydown', onSearchShortcut);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onSearchShortcut);
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

        <aside
            class="hidden w-64 shrink-0 flex-col border-r border-surface-200 bg-surface-0 lg:flex"
        >
            <div class="flex h-16 shrink-0 items-center gap-3 px-6">
                <img
                    v-if="clinic?.logo_url"
                    :src="clinic.logo_url"
                    :alt="clinic.name"
                    class="size-9 shrink-0 rounded-lg object-cover"
                />
                <span
                    class="truncate text-lg font-semibold"
                    :class="clinic ? 'text-surface-900' : 'text-brand'"
                >
                    {{ clinic?.name ?? t('auth.layout.brand') }}
                </span>
            </div>

            <div v-if="canViewPatients" class="px-3 pb-2">
                <PatientSearchSelect
                    ref="patientSearch"
                    class="w-full"
                    @select="goToPatient"
                />
            </div>

            <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    :class="linkClass(item.href)"
                >
                    <component :is="item.icon" class="size-5 shrink-0" />
                    {{ item.label }}
                </Link>
            </nav>

            <div
                class="flex shrink-0 flex-col gap-1 border-t border-surface-200 p-3"
            >
                <Link
                    v-for="item in bottomNavItems"
                    :key="item.href"
                    :href="item.href"
                    :class="linkClass(item.href)"
                >
                    <component :is="item.icon" class="size-5 shrink-0" />
                    {{ item.label }}
                </Link>

                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium text-surface-500 transition-colors hover:bg-surface-100 hover:text-surface-700"
                    @click="doLogout"
                >
                    <IconLogout class="size-5 shrink-0" />
                    {{ t('auth.logout') }}
                </button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header
                class="flex h-16 shrink-0 items-center justify-end gap-2 border-b border-surface-200 bg-surface-0 px-6"
            >
                <div class="flex shrink-0 items-center gap-1">
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
        </div>
    </div>
</template>
