<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { IconHome, IconLogout, IconSettings } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppToaster from '@/components/AppToaster.vue';
import { dashboard, logout, settings } from '@/routes';

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
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
]);

// Account/secondary items pinned to the bottom, above logout.
const bottomNavItems = computed(() => [
    { label: t('nav.settings'), href: settings().url, icon: IconSettings },
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
    <div class="flex min-h-screen bg-surface-50">
        <AppToaster />

        <aside
            class="hidden w-64 shrink-0 flex-col border-r border-surface-200 bg-surface-0 lg:flex"
        >
            <div class="flex h-16 items-center px-6">
                <span class="text-xl font-semibold text-brand">
                    {{ t('auth.layout.brand') }}
                </span>
            </div>

            <nav class="flex flex-1 flex-col gap-1 px-3 py-4">
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

            <div class="flex flex-col gap-1 border-t border-surface-200 p-3">
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

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex h-16 shrink-0 items-center border-b border-surface-200 bg-surface-0 px-6"
            >
                <p class="text-lg font-medium text-surface-900">
                    {{ t('app.greeting', { name: userName }) }}
                </p>
            </header>

            <main class="flex-1 p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
