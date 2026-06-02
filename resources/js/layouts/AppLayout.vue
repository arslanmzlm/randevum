<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconBuildingHospital,
    IconClipboardList,
    IconHome,
    IconLogout,
    IconPackage,
    IconSettings,
    IconStethoscope,
    IconUserCircle,
    IconUsers,
} from '@tabler/icons-vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppToaster from '@/components/AppToaster.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import { useContentWidth } from '@/composables/useContentWidth';
import { account, dashboard, logout } from '@/routes';
import { edit as clinicEdit } from '@/routes/clinic';
import { index as doctorsIndex, mine as doctorsMine } from '@/routes/doctors';
import { index as patientsIndex, show as patientShow } from '@/routes/patients';
import { index as productsIndex } from '@/routes/products';
import { index as servicesIndex } from '@/routes/services';
import type { PatientSearchResult } from '@/types/patient';

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const isDoctor = computed(() => page.props.auth?.isDoctor === true);
const canManageClinic = computed(
    () => page.props.auth?.canManageClinic === true,
);
const canManageDoctors = computed(
    () => page.props.auth?.canManageDoctors === true,
);
const canViewServices = computed(
    () => page.props.auth?.canViewServices === true,
);
const canViewProducts = computed(
    () => page.props.auth?.canViewProducts === true,
);
const canViewPatients = computed(
    () => page.props.auth?.canViewPatients === true,
);
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
    ...(canManageClinic.value
        ? [
              {
                  label: t('nav.clinic'),
                  href: clinicEdit().url,
                  icon: IconBuildingHospital,
              },
          ]
        : []),
    ...(canManageDoctors.value
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
    ...(canViewServices.value
        ? [
              {
                  label: t('nav.services'),
                  href: servicesIndex().url,
                  icon: IconClipboardList,
              },
          ]
        : []),
    ...(canViewProducts.value
        ? [
              {
                  label: t('nav.products'),
                  href: productsIndex().url,
                  icon: IconPackage,
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

function goToPatient(patient: PatientSearchResult): void {
    router.visit(patientShow(patient.id).url);
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
                class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-surface-200 bg-surface-0 px-6"
            >
                <div class="min-w-0 flex-1">
                    <PatientSearchSelect
                        v-if="canViewPatients"
                        class="w-full max-w-xs"
                        @select="goToPatient"
                    />
                </div>

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
