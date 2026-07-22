<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DateRangeFilter from '@/components/DateRangeFilter.vue';
import ExpenseFormDialog from '@/components/expenses/ExpenseFormDialog.vue';
import ExpenseList from '@/components/expenses/ExpenseList.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useExpenseList } from '@/composables/useExpenseList';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/expenses';
import type { Expense, ExpenseIndexProps } from '@/types/expense';

defineOptions({ layout: AppLayout });

const props = defineProps<ExpenseIndexProps>();

const { t } = useI18n();
const { can } = useCan();

const list = useExpenseList({
    url: index().url,
    filters: props.filters,
    query: props.query,
    currentPage: props.expenses.meta.current_page,
});

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
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('expense.title')" />

        <PageHeader
            :title="t('expense.title')"
            :description="t('expense.subtitle')"
            :breadcrumbs="[{ label: t('nav.expenses') }]"
        />

        <SectionCard class="flex flex-col gap-5" padding="p-5">
            <DateRangeFilter
                :filters="filters"
                :loading="list.loading.value"
                @change="list.setDateWindow"
            />
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
            :show-creator="false"
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
