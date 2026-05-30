<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconArrowsMinimize,
    IconBuildingHospital,
    IconHome,
    IconLogout,
    IconUserCircle,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppToaster from '@/components/AppToaster.vue';
import { useContentWidth } from '@/composables/useContentWidth';
import { account, dashboard, logout } from '@/routes';
import { edit as clinicEdit } from '@/routes/clinic';

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
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

// Only routes that already exist. Features add their own entry as they land.
const navItems = computed(() => [
    { label: t('nav.dashboard'), href: dashboard().url, icon: IconHome },
    {
        label: t('nav.clinic'),
        href: clinicEdit().url,
        icon: IconBuildingHospital,
    },
]);

// Account/secondary items pinned to the bottom, above logout.
const bottomNavItems = computed(() => [
    { label: t('nav.account'), href: account().url, icon: IconUserCircle },
]);

function isActive(href: string): boolean {
    return page.url === href || page.url.startsWith(`${href}/`);
}

function linkClass(href: string): string[] {
    return [
        'flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition-colors',
        isActive(href)
            ? 'bg-surface-100 text-surface-900'
            : 'text-surface-500 hover:bg-surface-100 hover:text-surface-700',
    ];
}

function doLogout(): void {
    router.post(logout().url);
}
</script>

<template>
    <div class="flex h-screen overflow-hidden bg-surface-50">
        <AppToaster />
        <ConfirmDialog />

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
                class="flex h-16 shrink-0 items-center justify-between gap-4 border-b border-surface-200 bg-surface-0 px-6"
            >
                <p class="truncate text-lg font-medium text-surface-900">
                    {{ t('app.greeting', { name: userName }) }}
                </p>

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
