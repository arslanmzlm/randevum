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
const props = defineProps<{ stats: DashboardStats }>();

const { t } = useI18n();
const { can } = useCan();
const { formatMoney } = useMoney();
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <template
            v-if="props.stats.appointments && can('appointments.viewAny')"
        >
            <StatCard
                :label="t('dashboard.stats.today_appointments')"
                :value="props.stats.appointments.today"
                :icon="IconCalendarClock"
                :sublabel="t('dashboard.stats.today')"
                accent="primary"
            />
            <StatCard
                :label="t('dashboard.stats.pending_confirmation')"
                :value="props.stats.appointments.pending"
                :icon="IconClockHour4"
                accent="amber"
            />
            <StatCard
                :label="t('dashboard.stats.this_week')"
                :value="props.stats.appointments.this_week"
                :icon="IconCalendarWeek"
                accent="sky"
            />
            <StatCard
                v-if="props.stats.appointments.no_show_rate"
                :label="t('dashboard.stats.no_show_rate')"
                :value="`${props.stats.appointments.no_show_rate.percent}%`"
                :icon="IconCalendarX"
                :sublabel="
                    t('dashboard.stats.no_show_rate_count', {
                        noShow: props.stats.appointments.no_show_rate.no_show,
                        expected:
                            props.stats.appointments.no_show_rate.expected,
                    })
                "
                accent="rose"
            />
        </template>

        <StatCard
            v-if="props.stats.revenue && can('transactions.viewAny')"
            :label="t('dashboard.stats.today_revenue')"
            :value="formatMoney(props.stats.revenue.today_collected)"
            :icon="IconCash"
            :sublabel="t('dashboard.stats.today')"
            accent="emerald"
        />
    </div>
</template>
