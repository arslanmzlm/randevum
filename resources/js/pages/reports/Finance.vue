<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconCash,
    IconChevronRight,
    IconReceipt,
    IconRefresh,
    IconReportMoney,
    IconWallet,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { clearCache } from '@/actions/App/Modules/Billing/Http/Controllers/FinanceController';
import StatCard from '@/components/dashboard/StatCard.vue';
import DateRangeFilter from '@/components/DateRangeFilter.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import type { DateWindow } from '@/composables/useExpenseList';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as expensesIndex } from '@/routes/expenses';
import { finance } from '@/routes/reports';
import type { FinanceReportProps } from '@/types/revenue';
import { formatDateOnly, formatMonthYear } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<FinanceReportProps>();

const { t, locale } = useI18n();
const { formatMoney } = useMoney();

const hasRangeRevenue = computed(
    () => props.revenue.range.by_period.length > 0,
);
const hasExpenseBreakdown = computed(
    () => props.expense.by_category.length > 0,
);
const netAccent = computed(() => (Number(props.net) < 0 ? 'rose' : 'primary'));

// The page now reports only, so it needs nothing from the expense list composable beyond the
// date window that scopes both breakdowns.
const loading = ref(false);

function setDateWindow(window: DateWindow): void {
    const params: Record<string, string | number> = {};

    if (window.entire) {
        params.entire = 1;
    } else {
        if (window.start) {
            params.start = window.start;
        }

        if (window.end) {
            params.end = window.end;
        }
    }

    router.get(finance().url, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

function periodLabel(period: string): string {
    return props.revenue.range.granularity === 'month'
        ? formatMonthYear(`${period}-01`, locale.value)
        : formatDateOnly(period, locale.value);
}

function categoryLabel(category: string | null): string {
    return category ?? t('finance.uncategorized');
}

function clearReportCache(): void {
    router.post(
        clearCache().url,
        {},
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('finance.title')" />

        <PageHeader
            :title="t('finance.title')"
            :description="t('finance.subtitle')"
            :breadcrumbs="[{ label: t('nav.finance') }]"
        >
            <template #actions>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('revenue.refresh')"
                    @click="clearReportCache"
                >
                    <template #icon>
                        <IconRefresh />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <SectionCard padding="p-5">
            <DateRangeFilter
                :filters="filters"
                :loading="loading"
                @change="setDateWindow"
            />
        </SectionCard>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard
                :label="t('finance.income')"
                :value="formatMoney(revenue.range.total)"
                :icon="IconCash"
                accent="emerald"
            />
            <StatCard
                :label="t('finance.expense')"
                :value="formatMoney(expense.total)"
                :icon="IconReceipt"
                accent="rose"
            />
            <StatCard
                :label="t('finance.net')"
                :value="formatMoney(net)"
                :icon="IconWallet"
                :accent="netAccent"
            />
        </div>

        <!-- One card per breakdown: payment-method and period answer different questions and
             were cramped side by side inside a single card. -->
        <div class="grid gap-6 lg:grid-cols-2">
            <SectionCard
                :icon="IconReportMoney"
                :title="t('revenue.by_method')"
            >
                <ul v-if="hasRangeRevenue" class="flex flex-col gap-2">
                    <li
                        v-for="row in revenue.range.by_method"
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

                <p v-else class="py-6 text-center text-sm text-surface-500">
                    {{ t('revenue.empty') }}
                </p>
            </SectionCard>

            <SectionCard
                :icon="IconReportMoney"
                :title="
                    revenue.range.granularity === 'month'
                        ? t('revenue.by_month')
                        : t('revenue.by_day')
                "
            >
                <DataTable
                    v-if="hasRangeRevenue"
                    :value="revenue.range.by_period"
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

                <p v-else class="py-6 text-center text-sm text-surface-500">
                    {{ t('revenue.empty') }}
                </p>
            </SectionCard>
        </div>

        <SectionCard
            :icon="IconReceipt"
            :title="t('finance.expense_breakdown')"
        >
            <ul v-if="hasExpenseBreakdown" class="flex flex-col gap-2">
                <li
                    v-for="row in expense.by_category"
                    :key="row.category ?? '__none__'"
                    class="flex items-center justify-between rounded-lg border border-surface-100 px-3 py-2 text-sm"
                >
                    <span class="text-surface-600">
                        {{ categoryLabel(row.category) }}
                    </span>
                    <span class="font-medium text-surface-900">
                        {{ formatMoney(row.total) }}
                    </span>
                </li>
            </ul>

            <p v-else class="py-6 text-center text-sm text-surface-500">
                {{ t('finance.expense_breakdown_empty') }}
            </p>

            <template #footer>
                <!-- The expense rows themselves live on /expenses; this page reports, it does not
                     duplicate the list. -->
                <Link
                    :href="expensesIndex().url"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                >
                    {{ t('finance.go_to_expenses') }}
                    <IconChevronRight class="size-4" />
                </Link>
            </template>
        </SectionCard>
    </div>
</template>
