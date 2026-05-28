<script setup lang="ts">
/**
 * Admin/Index — minimal placeholder for global-admin roles (superadmin / admin / moderator);
 * PostLoginRedirector sends them here rather than /dashboard. Full admin panel comes later.
 */
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { logout } from '@/routes';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

function doLogout(): void {
    router.post(logout().url);
}
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-surface-50 p-6"
    >
        <Head :title="t('admin.title')" />

        <div
            class="w-full max-w-md rounded-xl border border-surface-200 bg-white p-8 text-center shadow-sm"
        >
            <h1 class="mb-2 text-2xl font-semibold text-surface-900">
                {{ t('admin.title') }}
            </h1>
            <p class="mb-1 text-surface-500">
                {{ t('admin.welcome') }}
                <span class="font-medium text-surface-700">
                    {{ user?.first_name ?? user?.name ?? '' }}
                </span>
            </p>
            <p class="mb-8 text-xs text-surface-400">
                {{ t('admin.placeholder') }}
            </p>

            <Button
                :label="t('auth.logout')"
                severity="secondary"
                outlined
                @click="doLogout"
            />
        </div>
    </div>
</template>
