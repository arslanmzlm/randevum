<script setup lang="ts">
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';

// Thin shell around PrimeVue DataTable that wires the repetitive server-side
// lazy props from a `useTableFilters` instance, and owns the list card surface so
// index pages don't re-wrap it. The `#toolbar` slot holds the search + filter
// controls; the default slot forwards the `<Column>`s into the inner table;
// `#empty` is the no-results message.
withDefaults(
    defineProps<{
        value: unknown[];
        totalRecords: number;
        rows: number;
        first: number;
        loading?: boolean;
        sortField?: string;
        sortOrder?: number;
        rowsPerPageOptions?: number[];
        dataKey?: string;
    }>(),
    {
        loading: false,
        sortField: undefined,
        sortOrder: undefined,
        rowsPerPageOptions: () => [10, 20, 50],
        dataKey: 'id',
    },
);

const emit = defineEmits<{
    page: [event: DataTablePageEvent];
    sort: [event: DataTableSortEvent];
}>();
</script>

<template>
    <section
        class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
    >
        <div
            v-if="$slots.toolbar"
            class="flex flex-col gap-2 p-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <slot name="toolbar" />
        </div>

        <DataTable
            :value="value"
            :data-key="dataKey"
            lazy
            paginator
            removable-sort
            :rows="rows"
            :first="first"
            :total-records="totalRecords"
            :rows-per-page-options="rowsPerPageOptions"
            :loading="loading"
            :sort-field="sortField"
            :sort-order="sortOrder"
            class="text-sm"
            @page="emit('page', $event)"
            @sort="emit('sort', $event)"
        >
            <slot />

            <template #empty>
                <slot name="empty" />
            </template>
        </DataTable>
    </section>
</template>
