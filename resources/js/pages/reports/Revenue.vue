<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconCalendarStats, IconReportMoney, IconWallet } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatCard from '@/components/dashboard/StatCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import RevenueDateFilter from '@/components/reports/RevenueDateFilter.vue';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import type { RevenueReportProps } from '@/types/revenue';
import { formatDateOnly, formatMonthYear } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<RevenueReportProps>();

const { t, locale } = useI18n();
const { formatMoney } = useMoney();

const hasRangeRevenue = computed(() => props.range.by_period.length > 0);

function periodLabel(period: string): string {
    return props.range.granularity === 'month'
        ? formatMonthYear(`${period}-01`, locale.value)
        : formatDateOnly(period, locale.value);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('revenue.title')" />

        <PageHeader
            :title="t('revenue.title')"
            :description="t('revenue.subtitle')"
            :breadcrumbs="[{ label: t('nav.revenue') }]"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <StatCard
                :label="t('revenue.today')"
                :value="formatMoney(summary.today)"
                :icon="IconWallet"
                accent="emerald"
            />
            <StatCard
                :label="t('revenue.this_month')"
                :value="formatMoney(summary.this_month)"
                :icon="IconCalendarStats"
                accent="primary"
            />
        </div>

        <section
            class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-5"
        >
            <RevenueDateFilter :filters="filters" />

            <div
                class="flex items-baseline justify-between rounded-lg bg-surface-50 px-4 py-3"
            >
                <span class="text-sm text-surface-500">
                    {{ t('revenue.range_total') }}
                </span>
                <span class="text-2xl font-semibold text-surface-900">
                    {{ formatMoney(range.total) }}
                </span>
            </div>

            <div v-if="hasRangeRevenue" class="grid gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-3">
                    <h3 class="text-sm font-medium text-surface-600">
                        {{ t('revenue.by_method') }}
                    </h3>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="row in range.by_method"
                            :key="row.method"
                            class="flex items-center justify-between rounded-lg border border-surface-100 px-3 py-2 text-sm"
                        >
                            <span class="text-surface-600">
                                {{ t(`payment.method.${row.method}`) }}
                            </span>
                            <span class="font-medium text-surface-900">
                                {{ formatMoney(row.total) }}
                            </span>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-3">
                    <h3 class="text-sm font-medium text-surface-600">
                        {{
                            range.granularity === 'month'
                                ? t('revenue.by_month')
                                : t('revenue.by_day')
                        }}
                    </h3>
                    <DataTable
                        :value="range.by_period"
                        size="small"
                        scrollable
                        scroll-height="20rem"
                    >
                        <Column :header="t('revenue.columns.date')">
                            <template #body="{ data }">
                                {{ periodLabel(data.period) }}
                            </template>
                        </Column>
                        <Column :header="t('revenue.columns.total')">
                            <template #body="{ data }">
                                <span class="font-medium">
                                    {{ formatMoney(data.total) }}
                                </span>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-2 py-10 text-center"
            >
                <IconReportMoney class="size-9 text-surface-300" />
                <p class="text-sm text-surface-500">{{ t('revenue.empty') }}</p>
            </div>
        </section>
    </div>
</template>
