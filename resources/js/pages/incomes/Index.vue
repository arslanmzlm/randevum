<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DateRangeFilter from '@/components/DateRangeFilter.vue';
import ManualIncomeList from '@/components/incomes/ManualIncomeList.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useDateWindowList } from '@/composables/useDateWindowList';
import { manualIncomeResource } from '@/crud/manualIncome';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/incomes';
import type {
    ManualIncome,
    ManualIncomeIndexProps,
} from '@/types/manualIncome';

defineOptions({ layout: AppLayout });

const props = defineProps<ManualIncomeIndexProps>();

const { t } = useI18n();
const { can } = useCan();

const list = useDateWindowList({
    url: index().url,
    filters: props.filters,
    query: props.query,
    currentPage: props.incomes.meta.current_page,
});

// viewAny (reaches this page) and create are separate permissions (TransactionPolicy) — gate the
// `?new=1` deep link so a viewAny-only role can't pop an unsubmittable create form.
const canCreate = computed(() => can('transactions.create'));

// Create-only resource (a transaction is immutable), so no `editing` deep link is passed.
const { visible, item, openCreate, confirmDelete } =
    useCrudDialog<ManualIncome>({
        lang: manualIncomeResource.lang,
        destroy: (id: number) => destroy(id),
        canCreate: () => canCreate.value,
    });

function removeIncome(income: ManualIncome): void {
    confirmDelete(income, income.category ?? t('finance.uncategorized'));
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('income.title')" />

        <PageHeader
            :title="t('income.title')"
            :description="t('income.subtitle')"
            :breadcrumbs="[{ label: t('nav.incomes') }]"
        />

        <SectionCard class="flex flex-col gap-5" padding="p-5">
            <DateRangeFilter
                :filters="filters"
                :loading="list.loading.value"
                @change="list.setDateWindow"
            />
        </SectionCard>

        <ManualIncomeList
            :incomes="incomes"
            :categories="categories"
            :category="list.state.category"
            :loading="list.loading.value"
            :first="list.first.value"
            :per-page="list.state.per_page"
            :sort-field="list.sortField.value"
            :sort-order="list.sortOrder.value"
            :delete-window-seconds="deleteWindowSeconds"
            @page="list.onPage"
            @sort="list.onSort"
            @add="openCreate"
            @remove="removeIncome"
            @update:category="list.setCategory"
        />

        <CrudDialog
            v-model:visible="visible"
            :resource="manualIncomeResource"
            :item="item"
            :context="{ categories }"
        />
    </div>
</template>
