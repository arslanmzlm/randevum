import { router } from '@inertiajs/vue3';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { computed, reactive, ref } from 'vue';
import type {
    DateWindowFilters,
    SortOrderString,
    TableState,
} from '@/types/table';

/**
 * Shared list-state driver for the lists that filter by a clinic-local date window
 * (expenses, manual income, the report breakdowns), including the own/clinic scope switch.
 *
 * Unlike `useTableFilters`, those backends read their window (start/end/entire) and category
 * as FLAT query params (not `filter[...]`), so this serializes flat. A window/scope/category
 * change reloads the whole page — the window drives the summary cards too, not just the list;
 * only paging/sorting may narrow the reload, via `partialOnly`.
 */
interface UseDateWindowListOptions {
    /** Index route URL the reload targets. */
    url: string;
    filters: Omit<DateWindowFilters, 'category'> & { category?: string | null };
    query: Pick<TableState, 'sort' | 'per_page'>;
    /** `current_page` echoed by the paginator meta. */
    currentPage: number;
    /** 'own' or 'all' — only meaningful for viewers holding expenses.viewAny. */
    scope?: 'own' | 'all' | null;
    /**
     * Extra flat params re-sent on every reload (e.g. the report page's `tab`). Read at
     * serialize time, so a value the page keeps in a ref lands in the payload.
     */
    extraParams?: () => Record<string, string | number>;
    /**
     * Inertia `only` keys for reloads that change nothing but the list (paging, sorting).
     * Left out, those reload the whole page like every other change.
     */
    partialOnly?: string[];
}

export interface DateWindow {
    entire: boolean;
    start: string | null;
    end: string | null;
}

export function useDateWindowList(options: UseDateWindowListOptions) {
    const { url, filters, query, currentPage } = options;

    const sortToken = query.sort || '';

    const state = reactive({
        start: filters.start,
        end: filters.end,
        entire: filters.entire,
        category: filters.category ?? null,
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

        return { ...params, ...options.extraParams?.() };
    }

    function reload(
        reloadOptions: { resetPage?: boolean; only?: string[] } = {},
    ): void {
        if (reloadOptions.resetPage) {
            state.page = 1;
        }

        router.get(url, serialize(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: reloadOptions.only,
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
        reload({ resetPage: true });
    }

    function setScope(scope: 'own' | 'all'): void {
        state.scope = scope;
        reload({ resetPage: true });
    }

    function setCategory(category: string | null): void {
        state.category = category || null;
        reload({ resetPage: true });
    }

    function onPage(event: DataTablePageEvent): void {
        state.page = event.page + 1;
        state.per_page = event.rows;
        reload({ only: options.partialOnly });
    }

    function onSort(event: DataTableSortEvent): void {
        state.sort_field =
            typeof event.sortField === 'string' ? event.sortField : null;
        state.sort_order = event.sortOrder
            ? (String(event.sortOrder) as SortOrderString)
            : '';
        reload({ resetPage: true, only: options.partialOnly });
    }

    return {
        state,
        loading,
        first,
        sortField,
        sortOrder,
        reload,
        setDateWindow,
        setScope,
        setCategory,
        onPage,
        onSort,
    };
}
