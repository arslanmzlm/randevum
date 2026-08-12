<script setup lang="ts">
import {
    IconCalendarClock,
    IconCalendarWeek,
    IconCalendarX,
    IconCash,
    IconClockHour4,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import StatCard from '@/components/dashboard/StatCard.vue';
import { useCan } from '@/composables/useCan';
import { useMoney } from '@/composables/useMoney';
import type { DashboardStats } from '@/types/dashboard';

// Lays out the KPI tiles. Each tile renders only when its stats block is non-null (server already
// gated on permission) AND the viewer holds the same ability — mirroring the server gate per the
// auth-permissions rule. Money formats via useMoney (clinic currency); the revenue figure is always
// clinic-wide (no per-doctor split in the transaction model — accepted asymmetry).
defineProps<{ stats: DashboardStats }>();

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useMoney();
</script>

<template>
    <!-- Five tiles across on wide screens: a 4-column grid left the fifth stranded on its own row. -->
    <div
        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
    >
        <template v-if="stats.appointments && can('appointments.viewAny')">
            <StatCard
                :label="t('dashboard.stats.today_appointments')"
                :value="stats.appointments.today"
                :icon="IconCalendarClock"
                :sublabel="t('dashboard.stats.today')"
                accent="primary"
            />
            <StatCard
                :label="t('dashboard.stats.pending_confirmation')"
                :value="stats.appointments.pending"
                :icon="IconClockHour4"
                accent="amber"
            />
            <StatCard
                :label="t('dashboard.stats.this_week')"
                :value="stats.appointments.this_week"
                :icon="IconCalendarWeek"
                accent="sky"
            />
            <StatCard
                v-if="stats.appointments.no_show_rate"
                :label="t('dashboard.stats.no_show_rate')"
                :value="`${stats.appointments.no_show_rate.percent}%`"
                :icon="IconCalendarX"
                :sublabel="
                    t('dashboard.stats.no_show_rate_count', {
                        noShow: stats.appointments.no_show_rate.no_show,
                        expected: stats.appointments.no_show_rate.expected,
                    })
                "
                accent="rose"
            />
        </template>

        <StatCard
            v-if="stats.revenue && can('transactions.viewAny')"
            :label="t('dashboard.stats.today_revenue')"
            :value="formatMoney(stats.revenue.today_collected)"
            :icon="IconCash"
            :sublabel="t('dashboard.stats.today')"
            accent="emerald"
        />
    </div>
</template>
