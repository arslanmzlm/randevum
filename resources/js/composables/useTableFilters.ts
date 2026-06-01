import { router } from '@inertiajs/vue3';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { computed, reactive, ref, watch } from 'vue';
import type { SortOrderString } from '@/types/table';

/**
 * Owns the server-side lazy-list state machine shared by every `DataTable` list:
 * filter/sort/paginate state seeded from the URL (falling back to the
 * controller-echoed `filters` prop), a debounced search watcher, immediate
 * watchers on the custom filters, and a partial `router.get` that serializes a
 * clean, shareable query (null/empty/default values dropped).
 *
 * Intentionally separate from the table shell so a non-table list can reuse it.
 */

export type FilterType = 'string' | 'number' | 'boolean' | 'date' | 'array';

/** A value a custom list filter may hold. `null` means the filter is inactive. */
export type FilterValue = string | number | boolean | Date | string[] | null;

/** Typed declaration of one custom filter: how to parse it + its default value. */
export interface FilterDefinition<V extends FilterValue = FilterValue> {
    type: FilterType;
    value: V;
}

type FilterConfig<F extends Record<string, FilterValue>> = {
    [K in keyof F]: FilterDefinition<F[K]>;
};

type BaseState = {
    search: string;
    sort_field: string | null;
    sort_order: SortOrderString;
    per_page: number;
    page: number;
};

export type TableFilterState<F> = BaseState & F;

interface UseTableFiltersOptions<F extends Record<string, FilterValue>> {
    /** Index route URL the partial reload targets (e.g. `index().url`). */
    url: string;
    /** Partial-reload prop keys, e.g. `['services', 'filters']`. */
    only: string[];
    /** `current_page` echoed by the paginator meta. */
    currentPage: number;
    /** Initial base values, from the controller-echoed JSON:API list-state prop. */
    search?: string;
    /** Single JSON:API sort token, e.g. `name` or `-name` (`-` = desc). */
    sort?: string;
    perPage?: number;
    /** Typed custom filters; each resets page→1 and reloads immediately on change. */
    filters?: FilterConfig<F>;
    /** Debounce (ms) for the search watcher. */
    searchDebounce?: number;
}

function parseValue(type: FilterType, raw: string): FilterValue {
    switch (type) {
        case 'number':
            return Number(raw);
        case 'boolean':
            return raw === '1' || raw === 'true';
        case 'date':
            return new Date(raw);
        case 'array':
            return raw.split(',');
        default:
            return raw;
    }
}

function serializeValue(type: FilterType, value: FilterValue): string | number {
    if (type === 'boolean') {
        return value ? 1 : 0;
    }

    if (type === 'date' && value instanceof Date) {
        const year = value.getFullYear();
        const month = String(value.getMonth() + 1).padStart(2, '0');
        const day = String(value.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    if (type === 'array' && Array.isArray(value)) {
        return value.join(',');
    }

    return value as string | number;
}

export function useTableFilters<
    F extends Record<string, FilterValue> = Record<string, never>,
>(options: UseTableFiltersOptions<F>) {
    const {
        url,
        only,
        currentPage,
        search = '',
        sort = '',
        perPage = 20,
        filters = {} as FilterConfig<F>,
        searchDebounce = 350,
    } = options;

    const filterKeys = Object.keys(filters) as (keyof F)[];
    const query = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );

    // JSON:API query shape: filters under `filter[...]`, sort a single `sort` token
    // (`-` prefix = desc); pagination stays flat (`page` / `per_page`). URL wins, then
    // the controller-echoed fallback — both parse through the same single sort token.
    const sortToken = query.get('sort') ?? (sort || null);

    const base: BaseState = {
        search: query.get('filter[search]') ?? search,
        sort_field: sortToken ? sortToken.replace(/^-/, '') : null,
        sort_order: sortToken ? (sortToken.startsWith('-') ? '-1' : '1') : '',
        per_page: query.has('per_page')
            ? Number(query.get('per_page'))
            : perPage,
        page: query.has('page') ? Number(query.get('page')) : currentPage,
    };

    const custom = {} as F;

    for (const key of filterKeys) {
        const raw = query.get(`filter[${String(key)}]`);
        custom[key] = (
            raw !== null
                ? parseValue(filters[key].type, raw)
                : filters[key].value
        ) as F[typeof key];
    }

    const state = reactive({ ...base, ...custom }) as TableFilterState<F>;

    const loading = ref(false);
    const first = computed(() => (state.page - 1) * state.per_page);
    const sortField_ = computed(() => state.sort_field ?? undefined);
    const sortOrder_ = computed(() =>
        state.sort_order ? Number(state.sort_order) : undefined,
    );

    function serialize(): Record<
        string,
        string | number | Record<string, string | number>
    > {
        // Pagination stays flat; filters nest under `filter` and sort is a single
        // `-`-prefixed param — Inertia serializes the nested object to `filter[x]=…`.
        const params: Record<
            string,
            string | number | Record<string, string | number>
        > = {
            page: state.page,
            per_page: state.per_page,
        };

        const filter: Record<string, string | number> = {};

        if (state.search) {
            filter.search = state.search;
        }

        for (const key of filterKeys) {
            const value = state[
                key as keyof TableFilterState<F>
            ] as FilterValue;

            if (
                value === null ||
                value === '' ||
                (Array.isArray(value) && value.length === 0)
            ) {
                continue;
            }

            filter[String(key)] = serializeValue(filters[key].type, value);
        }

        if (Object.keys(filter).length > 0) {
            params.filter = filter;
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
            only,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        });
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

    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    watch(
        () => state.search,
        () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.page = 1;
                reload();
            }, searchDebounce);
        },
    );

    if (filterKeys.length > 0) {
        watch(
            filterKeys.map(
                (key) => () => state[key as keyof TableFilterState<F>],
            ),
            () => {
                state.page = 1;
                reload();
            },
        );
    }

    return {
        state,
        loading,
        first,
        sortField: sortField_,
        sortOrder: sortOrder_,
        onPage,
        onSort,
        reload,
    };
}
