<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UpcomingAppointmentsWidget from '@/components/appointments/UpcomingAppointmentsWidget.vue';
import FollowUpWidget from '@/components/dashboard/FollowUpWidget.vue';
import StatCardsRow from '@/components/dashboard/StatCardsRow.vue';
import TodayScheduleSummary from '@/components/dashboard/TodayScheduleSummary.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import type { DashboardProps } from '@/types/dashboard';

defineOptions({ layout: AppLayout });

defineProps<DashboardProps>();

const { t } = useI18n();
const { can } = useCan();
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('dashboard.title')" />

        <PageHeader :title="t('dashboard.title')" />

        <StatCardsRow :stats="stats" />

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <TodayScheduleSummary
                    v-if="can('appointments.viewAny') && stats.today_schedule"
                    :appointments="stats.today_schedule"
                />
            </div>

            <aside class="flex flex-col gap-6">
                <UpcomingAppointmentsWidget />
            </aside>
        </div>

        <!-- Full width: the call list is a wide table (patient, phone, doctor, date, note, action)
             and was cramped inside the two-thirds column. -->
        <FollowUpWidget
            v-if="can('followUps.view')"
            :follow-ups="followUps"
            :types="followUpTypes"
        />
    </div>
</template>
