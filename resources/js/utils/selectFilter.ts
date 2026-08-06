/** A search box only earns its place once the list is long enough to scan for. */
export const SELECT_FILTER_THRESHOLD = 8;

/** Whether a Select/MultiSelect over `count` options should show its search box. */
export function shouldFilterSelect(count: number): boolean {
    return count > SELECT_FILTER_THRESHOLD;
}
