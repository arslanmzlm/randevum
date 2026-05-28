<script setup lang="ts">
/**
 * Dashboard — minimal placeholder; the real dashboard (stat cards + calendar summary +
 * widgets) replaces this later (feature 1.3).
 */
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="rounded-xl border border-surface-200 bg-surface-0 p-8">
        <h1 class="mb-2 text-2xl font-semibold text-surface-900">
            {{ t('dashboard.title') }}
        </h1>
        <p class="text-sm text-surface-500">
            {{ t('dashboard.placeholder') }}
        </p>
    </div>
</template>
