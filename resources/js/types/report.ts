import type { ReportTab } from '@/types/enums';
import type { ExpenseReport, RevenueReport } from '@/types/revenue';
import type { Paginated } from '@/types/table';

/** Every tab except `finance`, which renders summary cards instead of a breakdown table. */
export type BreakdownTab = Exclude<ReportTab, 'finance'>;

/**
 * One aggregated breakdown row. `amount`/`count`/`average` carry a per-tab meaning (the
 * column headers say which); the appointment fields only come with the doctor tab.
 */
export interface BreakdownRow {
    /** Dimension key: doctor / service / product / appointment type / user id; null = unspecified. */
    id: number | null;
    label: string;
    /** Doctor tab only: the breakdown reaches soft-deleted profiles, so the label gets a badge. */
    is_deleted?: boolean;
    amount: string;
    count: number;
    average: string;
    appointment_count?: number;
    cancelled_count?: number;
    no_show_count?: number;
    /** 0–100 with one decimal. */
    cancelled_rate?: number;
    no_show_rate?: number;
    /** Branch tab only: the branch's expense total and `amount - expense`. */
    expense?: string;
    net?: string;
}

export type BreakdownColumnType = 'text' | 'money' | 'number' | 'percent';

export interface BreakdownColumn {
    field: keyof BreakdownRow;
    /** Message key under the `report` tree, e.g. `columns.label` or `amount_header.doctor`. */
    headerKey: string;
    type: BreakdownColumnType;
    /** Mirrors the server's per-tab sort allow-list — an unsortable column must not offer sorting. */
    sortable: boolean;
}

export interface BreakdownPayload {
    data: BreakdownRow[];
    meta: Paginated<BreakdownRow>['meta'];
    /** Computed over the whole window, so pagination never moves it. */
    /** `expense`/`net` are present on the branch (umbrella) tab only. */
    totals: { amount: string; count: number; expense?: string; net?: string };
}

/** `reports/Index` props — only one tab's data is populated per request. */
export interface ReportIndexProps {
    tab: ReportTab;
    filters: { start: string | null; end: string | null; entire: boolean };
    currency: string;
    revenue: RevenueReport | null;
    expense: ExpenseReport | null;
    net: string | null;
    breakdown: BreakdownPayload | null;
    /** Single JSON:API sort token (`-amount` = desc) plus the active page size. */
    query: { sort: string; per_page: number };
    /** The tenant holds more than one branch the viewer belongs to → offer the `branch` tab. */
    multiBranch: boolean;
}
