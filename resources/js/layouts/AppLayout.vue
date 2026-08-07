<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconBuildingHospital,
    IconCalendarDollar,
    IconCalendarEvent,
    IconCalendarOff,
    IconCalendarPlus,
    IconCalendarWeek,
    IconCashBanknote,
    IconClipboardList,
    IconFolders,
    IconHome,
    IconListDetails,
    IconLogout,
    IconMessage,
    IconMessage2,
    IconNotes,
    IconPackage,
    IconPhoneCall,
    IconReceipt,
    IconReportMoney,
    IconSearch,
    IconSettings,
    IconStethoscope,
    IconTag,
    IconTags,
    IconUserCircle,
    IconUsers,
    IconUserX,
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
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import { useCan } from '@/composables/useCan';
import { useContentWidth } from '@/composables/useContentWidth';
import { useQuickAccess } from '@/composables/useQuickAccess';
import { useSidebar } from '@/composables/useSidebar';
import { account, dashboard, logout } from '@/routes';
import { index as appointmentTypesIndex } from '@/routes/appointment-types';
import {
    bulkCreate as appointmentBulkCreate,
    create as appointmentCreate,
    index as appointmentsIndex,
} from '@/routes/appointments';
import { index as calendarIndex } from '@/routes/calendar';
import { index as casesIndex } from '@/routes/cases';
import { edit as clinicEdit } from '@/routes/clinic';
import { index as doctorsIndex, mine as doctorsMine } from '@/routes/doctors';
import { index as expensesIndex } from '@/routes/expenses';
import { index as followUpTypesIndex } from '@/routes/follow-up-types';
import { index as incomesIndex } from '@/routes/incomes';
import { index as patientsIndex, show as patientShow } from '@/routes/patients';
import { installments as paymentPlansIndex } from '@/routes/payment-plans';
import { index as productsIndex } from '@/routes/products';
import { index as reportsIndex } from '@/routes/reports';
import { index as availabilityIndex } from '@/routes/schedule-exceptions';
import { index as servicesIndex } from '@/routes/services';
import { index as smsLogsIndex } from '@/routes/sms-logs';
import { index as tagsIndex } from '@/routes/tags';
import { index as treatmentsIndex } from '@/routes/treatments';
import type { NavGroup, NavItem } from '@/types/nav';
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
const { collapsed, mobileOpen, closeMobile, setDesktopHidden } = useSidebar();
const {
    desktopOpen: quickOpen,
    mobileOpen: quickMobileOpen,
    closeMobile: closeQuickMobile,
    isWide: quickIsWide,
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
//
// The daily loop stays at the top level; everything else lives in a collapsible group, otherwise
// the list runs past twenty entries and nothing stands out.
const navItems = computed<NavItem[]>(() => [
    {
        label: t('nav.dashboard'),
        href: dashboard().url,
        component: 'Dashboard',
        icon: IconHome,
    },
    ...(can('appointments.viewAny')
        ? [
              {
                  label: t('nav.calendar'),
                  href: calendarIndex().url,
                  component: 'calendar/Index',
                  icon: IconCalendarWeek,
              },
              {
                  label: t('nav.appointments'),
                  href: appointmentsIndex().url,
                  component: 'appointments/Index',
                  icon: IconListDetails,
              },
          ]
        : []),
    ...(can('appointments.create')
        ? [
              {
                  label: t('nav.appointments_create'),
                  href: appointmentCreate().url,
                  component: 'appointments/Create',
                  icon: IconCalendarPlus,
              },
          ]
        : []),
    ...(canViewPatients.value
        ? [
              {
                  label: t('nav.patients'),
                  href: patientsIndex().url,
                  component: [
                      'patients/Index',
                      'patients/Show',
                      'patients/Create',
                      'patients/Edit',
                  ],
                  icon: IconUsers,
              },
          ]
        : []),
]);

const navGroups = computed<NavGroup[]>(() =>
    [
        {
            key: 'scheduling',
            label: t('nav.groups.scheduling'),
            icon: IconCalendarEvent,
            items: [
                ...(can('appointments.create')
                    ? [
                          {
                              label: t('nav.appointments_bulk'),
                              href: appointmentBulkCreate().url,
                              component: 'appointments/BulkCreate',
                              icon: IconCalendarEvent,
                          },
                      ]
                    : []),
                ...(can('appointments.viewAny')
                    ? [
                          {
                              label: t('nav.no_shows'),
                              href: appointmentsIndex({
                                  query: { filter: { status: 'no_show' } },
                              }).url,
                              component: 'appointments/Index',
                              match: { 'filter[status]': 'no_show' },
                              icon: IconUserX,
                          },
                      ]
                    : []),
                ...(can('scheduleExceptions.viewAny')
                    ? [
                          {
                              label: t('nav.availability'),
                              href: availabilityIndex().url,
                              component: 'availability/Index',
                              icon: IconCalendarOff,
                          },
                      ]
                    : []),
            ],
        },
        {
            key: 'clinic',
            label: t('nav.groups.clinic'),
            icon: IconBuildingHospital,
            items: [
                ...(can('clinic.update')
                    ? [
                          {
                              label: t('nav.clinic'),
                              href: clinicEdit().url,
                              component: 'clinic/Edit',
                              icon: IconBuildingHospital,
                          },
                      ]
                    : []),
                ...(can('doctors.create')
                    ? [
                          {
                              label: t('nav.doctors'),
                              href: doctorsIndex().url,
                              component: [
                                  'doctors/Index',
                                  'doctors/Create',
                                  'doctors/Edit',
                              ],
                              icon: IconStethoscope,
                          },
                      ]
                    : []),
                ...(can('cases.viewAny')
                    ? [
                          {
                              label: t('nav.cases'),
                              href: casesIndex().url,
                              component: ['cases/Index', 'cases/Show'],
                              icon: IconFolders,
                          },
                      ]
                    : []),
                ...(can('treatments.viewAny')
                    ? [
                          {
                              label: t('nav.treatments'),
                              href: treatmentsIndex().url,
                              component: [
                                  'treatments/Index',
                                  'treatments/Show',
                              ],
                              icon: IconNotes,
                          },
                      ]
                    : []),
                ...(can('services.viewAny')
                    ? [
                          {
                              label: t('nav.services'),
                              href: servicesIndex().url,
                              component: 'services/Index',
                              icon: IconClipboardList,
                          },
                      ]
                    : []),
                ...(can('products.viewAny')
                    ? [
                          {
                              label: t('nav.products'),
                              href: productsIndex().url,
                              component: 'products/Index',
                              icon: IconPackage,
                          },
                      ]
                    : []),
                ...(can('appointmentTypes.create')
                    ? [
                          {
                              label: t('nav.appointment_types'),
                              href: appointmentTypesIndex().url,
                              component: 'appointment-types/Index',
                              icon: IconTags,
                          },
                      ]
                    : []),
                ...(can('tags.manage')
                    ? [
                          {
                              label: t('nav.tags'),
                              href: tagsIndex().url,
                              component: 'tags/Index',
                              icon: IconTag,
                          },
                      ]
                    : []),
                ...(can('followUpTypes.manage')
                    ? [
                          {
                              label: t('nav.follow_up_types'),
                              href: followUpTypesIndex().url,
                              component: 'follow-up-types/Index',
                              icon: IconPhoneCall,
                          },
                      ]
                    : []),
            ],
        },
        {
            key: 'finance',
            label: t('nav.groups.finance'),
            icon: IconReportMoney,
            items: [
                ...(can('reports.revenue')
                    ? [
                          {
                              label: t('nav.reports'),
                              href: reportsIndex().url,
                              component: 'reports/Index',
                              icon: IconReportMoney,
                          },
                      ]
                    : []),
                ...(can('expenses.create')
                    ? [
                          {
                              label: t('nav.expenses'),
                              href: expensesIndex().url,
                              component: 'expenses/Index',
                              icon: IconReceipt,
                          },
                      ]
                    : []),
                ...(can('transactions.viewAny')
                    ? [
                          {
                              label: t('nav.incomes'),
                              href: incomesIndex().url,
                              component: 'incomes/Index',
                              icon: IconCashBanknote,
                          },
                      ]
                    : []),
                ...(can('paymentPlans.viewAny')
                    ? [
                          {
                              label: t('nav.payment_plans'),
                              href: paymentPlansIndex().url,
                              component: 'payment-plans/Index',
                              icon: IconCalendarDollar,
                          },
                      ]
                    : []),
            ],
        },
        {
            key: 'sms',
            label: t('nav.groups.sms'),
            icon: IconMessage,
            items: [
                ...(can('smsSettings.view')
                    ? [
                          {
                              // SMS preferences live on the clinic profile as a tab now; the nav
                              // still points straight at it.
                              label: t('nav.sms_settings'),
                              href: clinicEdit({ query: { tab: 'sms' } }).url,
                              component: 'clinic/Edit',
                              match: { tab: 'sms' },
                              icon: IconMessage,
                          },
                      ]
                    : []),
                ...(can('smsLogs.viewAny')
                    ? [
                          {
                              label: t('nav.sms_logs'),
                              href: smsLogsIndex().url,
                              component: 'sms-logs/Index',
                              icon: IconMessage2,
                          },
                      ]
                    : []),
            ],
        },
    ].filter((group) => group.items.length > 0),
);

// Account/secondary items pinned to the bottom, above logout.
const bottomNavItems = computed<NavItem[]>(() => [
    {
        label: t('nav.account'),
        href: account().url,
        component: 'account/Index',
        icon: IconUserCircle,
    },
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

const quickPanel = ref<{ focusSearch: () => void } | null>(null);
const searchPopover = ref();
const searchButton = ref();
const popoverSearch = ref<{ focus: () => void } | null>(null);

// Header search button (and Ctrl·Cmd+K): focus the docked search when the
// quick-access panel is actually visible, otherwise drop a popover under the
// button so search works without forcing the panel open.
function openPatientSearch(event: Event): void {
    if (!canViewPatients.value) {
        return;
    }

    if (quickIsWide.value && quickOpen.value) {
        nextTick(() => quickPanel.value?.focusSearch());

        return;
    }

    searchPopover.value?.toggle(event, searchButton.value?.$el);
}

// Autofocus the popover input once its overlay is in the DOM.
function onSearchPopoverShow(): void {
    nextTick(() => popoverSearch.value?.focus());
}

function goToPatient(patient: PatientSearchResult): void {
    closeMobile();
    closeQuickMobile();
    searchPopover.value?.hide();
    router.visit(patientShow(patient.id).url);
}

function onSearchShortcut(event: KeyboardEvent): void {
    if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
        return;
    }

    if (!canViewPatients.value) {
        return;
    }

    event.preventDefault();
    openPatientSearch(event);
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
                :nav-groups="navGroups"
                :bottom-nav-items="bottomNavItems"
                @navigate="closeMobile"
                @logout="doLogout"
            />
        </Drawer>

        <Drawer
            v-if="canViewUpcoming || canViewPatients"
            v-model:visible="quickMobileOpen"
            position="right"
            class="w-80"
            :pt="{ content: { class: 'p-0' }, header: { class: 'hidden' } }"
        >
            <QuickAccessSidebar
                :can-view-patients="canViewPatients"
                :can-view-upcoming="canViewUpcoming"
                @select-patient="goToPatient"
            />
        </Drawer>

        <aside
            v-if="!desktopSidebarHidden"
            class="hidden shrink-0 border-r border-surface-200 transition-[width] lg:flex"
            :class="railCollapsed ? 'lg:w-18' : 'lg:w-64'"
        >
            <AppSidebar
                class="w-full"
                :collapsed="railCollapsed"
                :clinic="clinic"
                :nav-items="navItems"
                :nav-groups="navGroups"
                :bottom-nav-items="bottomNavItems"
                @logout="doLogout"
            />
        </aside>

        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            <header
                class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-surface-200 bg-surface-0 px-6"
            >
                <SidebarToggle />

                <div class="flex shrink-0 items-center gap-1">
                    <Button
                        v-if="canViewPatients"
                        ref="searchButton"
                        type="button"
                        severity="secondary"
                        text
                        rounded
                        :aria-label="t('quick_access.search')"
                        @click="openPatientSearch"
                    >
                        <IconSearch class="size-5" />
                    </Button>

                    <Popover ref="searchPopover" @show="onSearchPopoverShow">
                        <div class="w-72 max-w-[80vw]">
                            <PatientSearchSelect
                                ref="popoverSearch"
                                class="w-full"
                                @select="goToPatient"
                            />
                        </div>
                    </Popover>

                    <QuickAccessToggle
                        v-if="canViewUpcoming || canViewPatients"
                    />

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
                    v-if="
                        (canViewUpcoming || canViewPatients) &&
                        quickOpen &&
                        quickIsWide
                    "
                    class="hidden shrink-0 border-l border-surface-200 bg-surface-0 xl:flex xl:w-[22rem]"
                >
                    <QuickAccessSidebar
                        ref="quickPanel"
                        class="w-full"
                        :can-view-patients="canViewPatients"
                        :can-view-upcoming="canViewUpcoming"
                        @select-patient="goToPatient"
                    />
                </aside>
            </div>
        </div>
    </div>
</template>
