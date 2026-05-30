<script setup lang="ts">
/**
 * Dashboard — minimal placeholder; the real dashboard (stat cards + calendar summary +
 * widgets) replaces this later (feature 1.3).
 */
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('dashboard.title')" />

        <PageHeader :title="t('dashboard.title')" />

        <div class="rounded-xl border border-surface-200 bg-surface-0 p-8">
            <p class="text-sm text-surface-500">
                {{ t('dashboard.placeholder') }}
            </p>
        </div>
    </div>
</template>
