<script setup lang="ts">
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import { useMoney } from '@/composables/useMoney';
import type {
    BreakdownColumn,
    BreakdownPayload,
    BreakdownRow,
} from '@/types/report';

// One generic table for every breakdown tab: the column set decides what is shown and how a
// cell is rendered, so a new tab needs a column definition, not another component.
const props = withDefaults(
    defineProps<{
        rows: BreakdownRow[];
        meta: BreakdownPayload['meta'];
        totals: BreakdownPayload['totals'];
        columns: BreakdownColumn[];
        /** One line saying which date column the tab is computed from. */
        hint: string;
        first: number;
        sortField?: string;
        sortOrder?: number;
        loading?: boolean;
    }>(),
    { sortField: undefined, sortOrder: undefined, loading: false },
);

const emit = defineEmits<{
    page: [event: DataTablePageEvent];
    sort: [event: DataTableSortEvent];
}>();

const { t, locale } = useI18n();
const { formatMoney } = useMoney();

// Rates arrive as 0–100; Intl takes a fraction and places the sign per locale (tr: %12,5).
const percentFormatter = computed(
    () =>
        new Intl.NumberFormat(locale.value, {
            style: 'percent',
            maximumFractionDigits: 1,
        }),
);

function cell(row: BreakdownRow, column: BreakdownColumn): string {
    const value = row[column.field];

    switch (column.type) {
        case 'money':
            return formatMoney(value as string);
        case 'percent':
            return percentFormatter.value.format(Number(value ?? 0) / 100);
        case 'number':
            return String(value ?? 0);
        default:
            return String(value ?? '');
    }
}

// Totals cover the whole window, so only the two window-wide metrics get a footer value.
function footer(column: BreakdownColumn): string {
    if (column.field === 'label') {
        return t('report.total');
    }

    if (column.field === 'amount') {
        return formatMoney(props.totals.amount);
    }

    if (column.field === 'count') {
        return String(props.totals.count);
    }

    return '';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-sm text-surface-500">{{ hint }}</p>

        <DataTableWrapper
            :value="rows"
            :total-records="meta.total"
            :rows="meta.per_page"
            :first="first"
            :loading="loading"
            :sort-field="sortField"
            :sort-order="sortOrder"
            @page="emit('page', $event)"
            @sort="emit('sort', $event)"
        >
            <Column
                v-for="column in columns"
                :key="column.field"
                :field="column.field"
                :header="t(`report.${column.headerKey}`)"
                :sortable="column.sortable"
                :class="column.type === 'text' ? 'min-w-48' : 'text-right'"
            >
                <template #body="{ data }">
                    <span
                        :class="
                            column.type === 'text'
                                ? 'font-medium text-surface-800'
                                : 'text-surface-700'
                        "
                    >
                        {{ cell(data as BreakdownRow, column) }}
                    </span>
                </template>
            </Column>

            <ColumnGroup type="footer">
                <Row>
                    <Column
                        v-for="column in columns"
                        :key="`footer-${column.field}`"
                        :footer="footer(column)"
                        :class="
                            column.type === 'text'
                                ? 'font-semibold'
                                : 'text-right font-semibold'
                        "
                    />
                </Row>
            </ColumnGroup>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('report.empty') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
