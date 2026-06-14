<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FollowUpWidget from '@/components/dashboard/FollowUpWidget.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import type { DashboardProps } from '@/types/dashboard';

defineOptions({ layout: AppLayout });

const props = defineProps<DashboardProps>();

const { t } = useI18n();
const { can } = useCan();
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('dashboard.title')" />

        <PageHeader :title="t('dashboard.title')" />

        <FollowUpWidget
            v-if="can('followUps.view')"
            :follow-ups="props.followUps"
        />

        <div
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-8"
        >
            <p class="text-sm text-surface-500">
                {{ t('dashboard.placeholder') }}
            </p>
        </div>
    </div>
</template>
