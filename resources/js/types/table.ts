/**
 * Shared server-side list (DataTable) contracts: the Laravel paginated
 * resource-collection shape plus the filter/sort base every list echoes back.
 * Consumed by `useTableFilters` + `DataTableWrapper` and the per-page filter types.
 */

/**
 * Laravel paginated resource-collection shape (`Resource::collection($paginator)`):
 * data rows + nested `meta` (counts) + `links`.
 */
export type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
};

/** PrimeVue sort order serialized as a string: '1' asc, '-1' desc, '' none. */
export type SortOrderString = '' | '1' | '-1';

/**
 * JSON:API list-state echoed by every server-side list (same shape as the URL query
 * and the serialized reload payload): a `filter` bag (search + page-specific keys),
 * a single `sort` token (`name` / `-name`), and flat `per_page`. Pages parameterize
 * the bag's extra keys via `F`.
 */
export type TableState<F = Record<string, never>> = {
    filter: { search: string } & F;
    sort: string;
    per_page: number;
};

/**
 * The money lists (expenses, manual income) filter by a clinic-local date window plus a
 * free-text category, sent as FLAT query params rather than a `filter` bag. One shape for
 * both, driven by `useDateWindowList`.
 */
export type DateWindowFilters = {
    start: string | null;
    end: string | null;
    entire: boolean;
    category: string | null;
};
