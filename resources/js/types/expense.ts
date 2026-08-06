import type { Paginated, TableState } from '@/types/table';

/** Canonical expense shape emitted by ExpenseResource (both list surfaces). */
export type Expense = {
    id: number;
    /** Calendar day, Y-m-d (no time-of-day). */
    expense_date: string;
    /** decimal:2 serialized as a string, e.g. "120.00". */
    amount: string;
    category: string | null;
    description: string | null;
    created_by: number;
    /** Present only on the all-clinic list ("who entered it"); absent on Giderlerim. */
    creator_name?: string | null;
    /** ISO-8601. */
    created_at: string;
};

/** Clinic-local date window + all-time toggle, plus the list-only category filter. */
export type ExpenseFilters = {
    start: string | null;
    end: string | null;
    entire: boolean;
    category: string | null;
};

/** Server-side list state echoed back (expenses carry no search/custom filters). */
export type ExpenseQuery = TableState;

/** Add/edit dialog fields. expense_date is a Date in the form, transformed to Y-m-d on submit. */
export type ExpenseFormData = {
    expense_date: Date | null;
    /** null on a fresh form so the currency input renders empty, not ₺0,00. */
    amount: number | null;
    category: string;
    description: string;
};

/** Giderlerim page — own expenses only. */
export type ExpenseIndexProps = {
    expenses: Paginated<Expense>;
    categories: string[];
    filters: ExpenseFilters;
    query: ExpenseQuery;
    currency: string;
    /** Whether the viewer may see the whole clinic's expenses (owner/manager). */
    canViewAll: boolean;
    /** Which list the server actually returned. */
    scope: 'own' | 'all';
    /** Row behind a `?edit=<id>` link, resolved server-side so it opens even when off-page. */
    editing: Expense | null;
};
