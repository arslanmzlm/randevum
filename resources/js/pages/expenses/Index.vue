<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DateRangeFilter from '@/components/DateRangeFilter.vue';
import ExpenseList from '@/components/expenses/ExpenseList.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useDateWindowList } from '@/composables/useDateWindowList';
import { expenseResource } from '@/crud/expense';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/expenses';
import type { Expense, ExpenseIndexProps } from '@/types/expense';

defineOptions({ layout: AppLayout });

const props = defineProps<ExpenseIndexProps>();

const { t } = useI18n();
const { can } = useCan();

const list = useDateWindowList({
    url: index().url,
    filters: props.filters,
    query: props.query,
    currentPage: props.expenses.meta.current_page,
    scope: props.canViewAll ? props.scope : null,
});

// Owner/manager land on the whole clinic (the finance report links here for exactly that) and
// can narrow to their own rows; everyone else only ever sees their own and gets no switch.
const scopeOptions = computed(() => [
    { label: t('expense.scope.all'), value: 'all' },
    { label: t('expense.scope.own'), value: 'own' },
]);

// index() authorizes on `expenses.create` today, so this is a no-op in practice — but
// viewAny/create are separate permissions (ExpensePolicy), so gate the `?new=1` deep link
// explicitly rather than relying on that coupling holding forever.
const canCreate = computed(() => can('expenses.create'));

// Deletion stays in ExpenseList: it gates each row on ownership and has its own confirmation.
const { visible, item, openCreate, openEdit } = useCrudDialog<Expense>({
    lang: expenseResource.lang,
    editing: () => props.editing,
    canCreate: () => canCreate.value,
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('expense.title')" />

        <PageHeader
            :title="t('expense.title')"
            :description="t('expense.subtitle')"
            :breadcrumbs="[{ label: t('nav.expenses') }]"
        >
            <template v-if="canViewAll" #actions>
                <SelectButton
                    :model-value="list.state.scope"
                    :options="scopeOptions"
                    option-label="label"
                    option-value="value"
                    :allow-empty="false"
                    @update:model-value="list.setScope"
                />
            </template>
        </PageHeader>

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
            :show-creator="list.state.scope === 'all'"
            :can-manage-any="can('expenses.viewAny')"
            @page="list.onPage"
            @sort="list.onSort"
            @add="openCreate"
            @edit="openEdit"
            @update:category="list.setCategory"
        />

        <CrudDialog
            v-model:visible="visible"
            :resource="expenseResource"
            :item="item"
            :context="{ categories }"
        />
    </div>
</template>
