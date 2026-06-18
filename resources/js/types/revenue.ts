import type { PaymentMethod } from '@/types/enums';

export interface RevenueMethodTotal {
    method: PaymentMethod;
    total: string;
}

export interface RevenuePeriodTotal {
    /** Clinic-local bucket key: Y-m-d when daily, Y-m when monthly. */
    period: string;
    total: string;
}

export interface RevenueFilters {
    /** Clinic-local Y-m-d, or null when all-time. */
    start: string | null;
    end: string | null;
    entire: boolean;
}

export interface RevenueReportProps {
    summary: {
        today: string;
        this_month: string;
    };
    range: {
        start: string;
        end: string;
        entire: boolean;
        /** Daily for spans up to ~3 months, monthly beyond — keeps the breakdown bounded. */
        granularity: 'day' | 'month';
        total: string;
        by_method: RevenueMethodTotal[];
        by_period: RevenuePeriodTotal[];
    };
    filters: RevenueFilters;
}
