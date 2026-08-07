import type { PaymentMethod } from '@/types/enums';
import type { DateWindowFilters, Paginated, TableState } from '@/types/table';

/**
 * Clinic income with no patient behind it (rent, wholesale, courses) — a `transactions` row with
 * `patient_id = null`, emitted by ManualIncomeResource. Never part of a patient balance.
 */
export type ManualIncome = {
    id: number;
    /** ISO-8601 (UTC); rendered through useDateTime() in the clinic timezone. */
    paid_at: string;
    /** decimal:2 serialized as a string, e.g. "1500.00". */
    amount: string;
    payment_method: PaymentMethod;
    category: string | null;
    note: string | null;
    created_by: number | null;
    creator_name?: string | null;
    /** ISO-8601 — drives the client-side delete-window hint. */
    created_at: string;
};

/** Add dialog fields. paid_at is a Date in the form, transformed to Y-m-d on submit. */
export type ManualIncomeFormData = {
    paid_at: Date | null;
    /** null on a fresh form so the currency input renders empty, not ₺0,00. */
    amount: number | null;
    payment_method: PaymentMethod | null;
    category: string;
    note: string;
};

export type ManualIncomeIndexProps = {
    incomes: Paginated<ManualIncome>;
    categories: string[];
    filters: DateWindowFilters;
    query: TableState;
    currency: string;
    /** Immutability window (seconds) a row may still be hard-deleted in — mirrors the policy. */
    deleteWindowSeconds: number;
};
