<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconCash,
    IconChevronRight,
    IconReceipt,
    IconReportMoney,
    IconWallet,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatCard from '@/components/dashboard/StatCard.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useMoney } from '@/composables/useMoney';
import { index as expensesIndex } from '@/routes/expenses';
import { index as incomesIndex } from '@/routes/incomes';
import type { ExpenseReport, RevenueReport } from '@/types/revenue';
import { formatDateOnly, formatMonthYear } from '@/utils/datetime';

const props = defineProps<{
    revenue: RevenueReport;
    expense: ExpenseReport;
    /** revenue.range.total − expense.total (bcmath); may be negative. */
    net: string;
}>();

const { t, locale } = useI18n();
const { formatMoney } = useMoney();

const hasRangeRevenue = computed(
    () => props.revenue.range.by_period.length > 0,
);
const hasExpenseBreakdown = computed(
    () => props.expense.by_category.length > 0,
);
const hasManualBreakdown = computed(
    () => props.revenue.range.manual_by_category.length > 0,
);
const netAccent = computed(() => (Number(props.net) < 0 ? 'rose' : 'primary'));

function periodLabel(period: string): string {
    return props.revenue.range.granularity === 'month'
        ? formatMonthYear(`${period}-01`, locale.value)
        : formatDateOnly(period, locale.value);
}

function categoryLabel(category: string | null): string {
    return category ?? t('finance.uncategorized');
}
</script>

<template>
    <div class="flex flex-col gap-6">
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

        <SectionCard :icon="IconCash" :title="t('finance.income_breakdown')">
            <ul class="flex flex-col gap-2">
                <li
                    class="flex items-center justify-between rounded-lg border border-surface-100 px-3 py-2 text-sm"
                >
                    <span class="text-surface-600">
                        {{ t('finance.income_patient') }}
                    </span>
                    <span class="font-medium text-surface-900">
                        {{ formatMoney(revenue.range.patient_total) }}
                    </span>
                </li>
                <li
                    class="flex items-center justify-between rounded-lg border border-surface-100 px-3 py-2 text-sm"
                >
                    <span class="text-surface-600">
                        {{ t('finance.income_manual') }}
                    </span>
                    <span class="font-medium text-surface-900">
                        {{ formatMoney(revenue.range.manual_total) }}
                    </span>
                </li>
            </ul>

            <!-- The category split only concerns manual income; patient collections are broken
                 down by payment method above. -->
            <div v-if="hasManualBreakdown" class="mt-4 flex flex-col gap-2">
                <h3 class="text-sm font-medium text-surface-700">
                    {{ t('finance.income_manual_by_category') }}
                </h3>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="row in revenue.range.manual_by_category"
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
            </div>

            <template #footer>
                <Link
                    :href="incomesIndex().url"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                >
                    {{ t('finance.go_to_incomes') }}
                    <IconChevronRight class="size-4" />
                </Link>
            </template>
        </SectionCard>

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
