import { router } from '@inertiajs/vue3';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { computed, reactive, ref } from 'vue';
import type { ExpenseFilters, ExpenseQuery } from '@/types/expense';
import type { SortOrderString } from '@/types/table';

/**
 * Shared list-state driver for the expense list, including the own/clinic scope switch.
 *
 * Unlike `useTableFilters`, the expense/finance backend reads its window (start/end/entire)
 * and category as FLAT query params (not `filter[...]`), so this serializes flat and never
 * uses `only` — a filter change reloads the whole page, which is exactly what the finance
 * page needs (the date window drives the revenue/expense/net cards too, not just the list).
 */
interface UseExpenseListOptions {
    /** Index route URL the reload targets. */
    url: string;
    filters: ExpenseFilters;
    query: ExpenseQuery;
    /** `current_page` echoed by the paginator meta. */
    currentPage: number;
    /** 'own' or 'all' — only meaningful for viewers holding expenses.viewAny. */
    scope?: 'own' | 'all' | null;
}

export interface DateWindow {
    entire: boolean;
    start: string | null;
    end: string | null;
}

export function useExpenseList(options: UseExpenseListOptions) {
    const { url, filters, query, currentPage } = options;

    const sortToken = query.sort || '';

    const state = reactive({
        start: filters.start,
        end: filters.end,
        entire: filters.entire,
        category: filters.category,
        sort_field: sortToken ? sortToken.replace(/^-/, '') : null,
        sort_order: (sortToken
            ? sortToken.startsWith('-')
                ? '-1'
                : '1'
            : '') as SortOrderString,
        per_page: query.per_page,
        page: currentPage,
        scope: options.scope ?? null,
    });

    const loading = ref(false);
    const first = computed(() => (state.page - 1) * state.per_page);
    const sortField = computed(() => state.sort_field ?? undefined);
    const sortOrder = computed(() =>
        state.sort_order ? Number(state.sort_order) : undefined,
    );

    function serialize(): Record<string, string | number> {
        const params: Record<string, string | number> = {
            page: state.page,
            per_page: state.per_page,
        };

        if (state.entire) {
            params.entire = 1;
        } else {
            if (state.start) {
                params.start = state.start;
            }

            if (state.end) {
                params.end = state.end;
            }
        }

        if (state.category) {
            params.category = state.category;
        }

        if (state.scope) {
            params.scope = state.scope;
        }

        if (state.sort_field) {
            params.sort =
                (state.sort_order === '-1' ? '-' : '') + state.sort_field;
        }

        return params;
    }

    function reload(): void {
        router.get(url, serialize(), {
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

    function setDateWindow(window: DateWindow): void {
        state.entire = window.entire;
        state.start = window.start;
        state.end = window.end;
        state.page = 1;
        reload();
    }

    function setScope(scope: 'own' | 'all'): void {
        state.scope = scope;
        state.page = 1;
        reload();
    }

    function setCategory(category: string | null): void {
        state.category = category || null;
        state.page = 1;
        reload();
    }

    function onPage(event: DataTablePageEvent): void {
        state.page = event.page + 1;
        state.per_page = event.rows;
        reload();
    }

    function onSort(event: DataTableSortEvent): void {
        state.sort_field =
            typeof event.sortField === 'string' ? event.sortField : null;
        state.sort_order = event.sortOrder
            ? (String(event.sortOrder) as SortOrderString)
            : '';
        state.page = 1;
        reload();
    }

    return {
        state,
        loading,
        first,
        sortField,
        sortOrder,
        setDateWindow,
        setScope,
        setCategory,
        onPage,
        onSort,
    };
}
