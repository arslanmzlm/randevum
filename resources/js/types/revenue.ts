import type { PaymentMethod } from '@/types/enums';
import type { ExpenseFilters } from '@/types/expense';

export interface RevenueMethodTotal {
    method: PaymentMethod;
    total: string;
}

export interface RevenuePeriodTotal {
    /** Clinic-local bucket key: Y-m-d when daily, Y-m when monthly. */
    period: string;
    total: string;
}

export interface RevenueRange {
    start: string;
    end: string;
    entire: boolean;
    /** Daily for spans up to ~3 months, monthly beyond — keeps the breakdown bounded. */
    granularity: 'day' | 'month';
    total: string;
    by_method: RevenueMethodTotal[];
    by_period: RevenuePeriodTotal[];
}

/** RevenueReportService output, nested under the finance page's `revenue` prop. */
export interface RevenueReport {
    summary: {
        today: string;
        this_month: string;
    };
    range: RevenueRange;
}

export interface ExpenseCategoryTotal {
    category: string | null;
    total: string;
}

/** ExpenseReportService window output. */
export interface ExpenseReport {
    total: string;
    by_category: ExpenseCategoryTotal[];
}

/** Unified finance page (revenue + expense + net) — owner/manager. */
export interface FinanceReportProps {
    revenue: RevenueReport;
    expense: ExpenseReport;
    /** revenue.range.total − expense.total (bcmath); may be negative. */
    net: string;
    filters: ExpenseFilters;
    currency: string;
}
