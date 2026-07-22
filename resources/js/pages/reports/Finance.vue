<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconCash,
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
import ExpenseFormDialog from '@/components/expenses/ExpenseFormDialog.vue';
import ExpenseList from '@/components/expenses/ExpenseList.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useExpenseList } from '@/composables/useExpenseList';
import { useMoney } from '@/composables/useMoney';
import AppLayout from '@/layouts/AppLayout.vue';
import { finance } from '@/routes/reports';
import type { Expense } from '@/types/expense';
import type { FinanceReportProps } from '@/types/revenue';
import { formatDateOnly, formatMonthYear } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<FinanceReportProps>();

const { t, locale } = useI18n();
const { can } = useCan();
const { formatMoney } = useMoney();

const list = useExpenseList({
    url: finance().url,
    filters: props.filters,
    query: props.query,
    currentPage: props.expenses.meta.current_page,
});

const hasRangeRevenue = computed(
    () => props.revenue.range.by_period.length > 0,
);
const hasExpenseBreakdown = computed(
    () => props.expense.by_category.length > 0,
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

const dialogVisible = ref(false);
const editTarget = ref<Expense | null>(null);

function openCreate(): void {
    editTarget.value = null;
    dialogVisible.value = true;
}

function openEdit(expense: Expense): void {
    editTarget.value = expense;
    dialogVisible.value = true;
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
                :loading="list.loading.value"
                @change="list.setDateWindow"
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

        <SectionCard
            :icon="IconReportMoney"
            :title="t('finance.revenue_breakdown')"
        >
            <div v-if="hasRangeRevenue" class="grid gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-3">
                    <h3 class="text-sm font-medium text-surface-600">
                        {{ t('revenue.by_method') }}
                    </h3>
                    <ul class="flex flex-col gap-2">
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
                </div>

                <div class="flex flex-col gap-3">
                    <h3 class="text-sm font-medium text-surface-600">
                        {{
                            revenue.range.granularity === 'month'
                                ? t('revenue.by_month')
                                : t('revenue.by_day')
                        }}
                    </h3>
                    <DataTable
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
                </div>
            </div>

            <p v-else class="py-6 text-center text-sm text-surface-500">
                {{ t('revenue.empty') }}
            </p>
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
        </SectionCard>

        <ExpenseList
            :expenses="expenses"
            :categories="categories"
            :category="list.state.category"
            :loading="list.loading.value"
            :first="list.first.value"
            :per-page="list.state.per_page"
            :sort-field="list.sortField.value"
            :sort-order="list.sortOrder.value"
            :show-creator="true"
            :can-manage-any="can('expenses.viewAny')"
            @page="list.onPage"
            @sort="list.onSort"
            @add="openCreate"
            @edit="openEdit"
            @update:category="list.setCategory"
        />

        <ExpenseFormDialog
            v-model:visible="dialogVisible"
            :expense="editTarget"
            :categories="categories"
            :currency="currency"
        />
    </div>
</template>
