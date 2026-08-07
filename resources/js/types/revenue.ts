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
    /** ALL revenue in the window: patient collections + manual (patient-less) income. */
    total: string;
    /** The patient-collection share of `total`. */
    patient_total: string;
    /** The manual-income share of `total`. */
    manual_total: string;
    /** Manual income split by its free-text category (null = uncategorized), desc by total. */
    manual_by_category: CategoryTotal[];
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

/** One free-text category bucket (null = uncategorized) — shared by the expense and income splits. */
export interface CategoryTotal {
    category: string | null;
    total: string;
}

/** ExpenseReportService window output. */
export interface ExpenseReport {
    total: string;
    by_category: CategoryTotal[];
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
