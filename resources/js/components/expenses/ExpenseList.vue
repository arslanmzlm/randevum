<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import { useCan } from '@/composables/useCan';
import { useMoney } from '@/composables/useMoney';
import { destroy } from '@/routes/expenses';
import type { Expense } from '@/types/expense';
import type { Paginated } from '@/types/table';
import { formatDateOnly } from '@/utils/datetime';

// Shared expense table: server-side paginated/sorted DataTable with a category filter and
// per-row edit/delete gated by ownership or the manage-any permission. Used by both the
// finance overview (showCreator) and Giderlerim. Delete is handled here (uniform); add/edit
// bubble up so the page's ExpenseFormDialog opens against the right row.
const props = defineProps<{
    expenses: Paginated<Expense>;
    categories: string[];
    category: string | null;
    loading: boolean;
    first: number;
    perPage: number;
    sortField?: string;
    sortOrder?: number;
    /** Show the "who entered it" column (all-clinic finance list only). */
    showCreator: boolean;
    /** Owner/manager may edit/delete any row, not just their own. */
    canManageAny: boolean;
}>();

const emit = defineEmits<{
    page: [DataTablePageEvent];
    sort: [DataTableSortEvent];
    add: [];
    edit: [Expense];
    'update:category': [string | null];
}>();

const { t, locale } = useI18n();
const { formatMoney } = useMoney();
const { can } = useCan();
const confirm = useConfirm();
const page = usePage();

const userId = computed(() => page.props.auth?.user?.id ?? null);

const categoryModel = computed({
    get: () => props.category,
    set: (value: string | null) => emit('update:category', value ?? null),
});

function canEditRow(expense: Expense): boolean {
    return props.canManageAny || expense.created_by === userId.value;
}

function removeExpense(expense: Expense): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('expense.delete_confirm'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(expense.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <DataTableWrapper
        :value="expenses.data"
        :total-records="expenses.meta.total"
        :rows="perPage"
        :first="first"
        :loading="loading"
        :sort-field="sortField"
        :sort-order="sortOrder"
        @page="emit('page', $event)"
        @sort="emit('sort', $event)"
    >
        <template #toolbar>
            <Select
                v-model="categoryModel"
                :options="categories"
                :placeholder="t('expense.filter_category')"
                show-clear
                class="w-full sm:w-56"
            />
            <Button
                v-if="can('expenses.create')"
                type="button"
                class="sm:ml-auto"
                :label="t('expense.add')"
                @click="emit('add')"
            >
                <template #icon>
                    <IconPlus />
                </template>
            </Button>
        </template>

        <Column
            field="expense_date"
            :header="t('expense.columns.expense_date')"
            sortable
        >
            <template #body="{ data }">
                {{ formatDateOnly(data.expense_date, locale) }}
            </template>
        </Column>

        <Column
            field="category"
            :header="t('expense.columns.category')"
            sortable
        >
            <template #body="{ data }">
                <span v-if="data.category">{{ data.category }}</span>
                <span v-else class="text-surface-400">—</span>
            </template>
        </Column>

        <Column :header="t('expense.columns.description')">
            <template #body="{ data }">
                <span
                    v-if="data.description"
                    class="block max-w-xs truncate text-surface-600"
                >
                    {{ data.description }}
                </span>
                <span v-else class="text-surface-400">—</span>
            </template>
        </Column>

        <Column v-if="showCreator" :header="t('expense.columns.creator')">
            <template #body="{ data }">
                <span class="text-surface-600">{{ data.creator_name }}</span>
            </template>
        </Column>

        <Column
            field="amount"
            :header="t('expense.columns.amount')"
            sortable
            class="text-right"
        >
            <template #body="{ data }">
                <span class="font-medium text-surface-900">
                    {{ formatMoney(data.amount) }}
                </span>
            </template>
        </Column>

        <Column class="w-28">
            <template #header>
                <span class="sr-only">{{ t('expense.columns.actions') }}</span>
            </template>
            <template #body="{ data }">
                <div
                    v-if="canEditRow(data)"
                    class="flex items-center justify-end gap-1"
                >
                    <Button
                        type="button"
                        severity="secondary"
                        outlined
                        size="small"
                        :aria-label="t('expense.edit')"
                        v-tooltip.top="t('expense.edit')"
                        @click="emit('edit', data)"
                    >
                        <IconPencil />
                    </Button>
                    <Button
                        type="button"
                        severity="danger"
                        text
                        size="small"
                        :aria-label="t('expense.remove')"
                        @click="removeExpense(data)"
                    >
                        <IconTrash />
                    </Button>
                </div>
            </template>
        </Column>

        <template #empty>
            <div class="px-6 py-10 text-center text-sm text-surface-500">
                {{ t('expense.empty') }}
            </div>
        </template>
    </DataTableWrapper>
</template>
