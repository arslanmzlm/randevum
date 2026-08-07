<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconPlus, IconTrash } from '@tabler/icons-vue';
import { useNow } from '@vueuse/core';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import type { ManualIncome } from '@/types/manualIncome';
import type { Paginated } from '@/types/table';

// Manual (patient-less) income table: server-side paginated/sorted, with a category filter.
// A row is immutable — the only mutating action is a hard delete inside the immutability
// window, which the page confirms before firing.
const props = defineProps<{
    incomes: Paginated<ManualIncome>;
    categories: string[];
    category: string | null;
    loading: boolean;
    first: number;
    perPage: number;
    sortField?: string;
    sortOrder?: number;
    /** Seconds after `created_at` a row may still be deleted (server policy mirror). */
    deleteWindowSeconds: number;
}>();

const emit = defineEmits<{
    page: [DataTablePageEvent];
    sort: [DataTableSortEvent];
    add: [];
    remove: [ManualIncome];
    'update:category': [string | null];
}>();

const { t } = useI18n();
const { formatMoney } = useMoney();
const { formatDate } = useDateTime();
const { can } = useCan();
const page = usePage();

// The window expires while the page sits open, so the delete button has to follow the clock
// rather than the render that first drew it.
const now = useNow({ interval: 30_000 });

const userId = computed(() => page.props.auth?.user?.id ?? null);

const categoryModel = computed({
    get: () => props.category,
    set: (value: string | null) => emit('update:category', value ?? null),
});

function canRemoveRow(income: ManualIncome): boolean {
    const elapsed =
        (now.value.getTime() - new Date(income.created_at).getTime()) / 1000;

    return (
        elapsed <= props.deleteWindowSeconds &&
        (income.created_by === userId.value || can('transactions.refund'))
    );
}
</script>

<template>
    <DataTableWrapper
        :value="incomes.data"
        :total-records="incomes.meta.total"
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
                :placeholder="t('income.filter_category')"
                show-clear
                class="w-full sm:w-56"
            />
            <Button
                v-if="can('transactions.create')"
                type="button"
                class="sm:ml-auto"
                :label="t('income.add')"
                @click="emit('add')"
            >
                <template #icon>
                    <IconPlus />
                </template>
            </Button>
        </template>

        <Column field="paid_at" :header="t('income.columns.paid_at')" sortable>
            <template #body="{ data }">
                {{ formatDate(data.paid_at) }}
            </template>
        </Column>

        <Column
            field="category"
            :header="t('income.columns.category')"
            sortable
        >
            <template #body="{ data }">
                <span v-if="data.category">{{ data.category }}</span>
                <span v-else class="text-surface-400">—</span>
            </template>
        </Column>

        <Column :header="t('income.columns.note')">
            <template #body="{ data }">
                <span
                    v-if="data.note"
                    class="block max-w-xs truncate text-surface-600"
                >
                    {{ data.note }}
                </span>
                <span v-else class="text-surface-400">—</span>
            </template>
        </Column>

        <Column :header="t('income.columns.payment_method')" class="w-32">
            <template #body="{ data }">
                <span class="text-surface-600">
                    {{ t(`payment.method.${data.payment_method}`) }}
                </span>
            </template>
        </Column>

        <Column :header="t('income.columns.creator')">
            <template #body="{ data }">
                <span class="text-surface-600">{{ data.creator_name }}</span>
            </template>
        </Column>

        <Column
            field="amount"
            :header="t('income.columns.amount')"
            sortable
            class="text-right"
        >
            <template #body="{ data }">
                <span class="font-medium text-surface-900">
                    {{ formatMoney(data.amount) }}
                </span>
            </template>
        </Column>

        <Column class="w-20">
            <template #header>
                <span class="sr-only">{{ t('income.columns.actions') }}</span>
            </template>
            <template #body="{ data }">
                <div class="flex items-center justify-end">
                    <Button
                        v-if="canRemoveRow(data)"
                        v-tooltip.top="t('income.delete_window_hint')"
                        type="button"
                        severity="danger"
                        text
                        size="small"
                        :aria-label="t('income.remove')"
                        @click="emit('remove', data)"
                    >
                        <IconTrash />
                    </Button>
                </div>
            </template>
        </Column>

        <template #empty>
            <div class="px-6 py-10 text-center text-sm text-surface-500">
                {{ t('income.empty') }}
            </div>
        </template>
    </DataTableWrapper>
</template>
