import type {
    InstallmentStatus,
    PaymentMethod,
    PaymentPlanStatus,
} from '@/types/enums';

/** One installment row on the collections (pending-installments) screen. */
export type PendingInstallment = {
    id: number;
    plan_id: number;
    patient_id: number;
    patient_name: string;
    treatment_id: number | null;
    sequence: number;
    /** Total installments in the plan — the row renders "sequence / installment_count". */
    installment_count: number;
    /** Y-m-d (tz-less calendar date). */
    due_date: string;
    /** Decimal string. */
    amount: string;
    /** Derived from the installment's transactions (refunds netted out); "0.00" when untouched. */
    collected_amount: string;
    /** amount − collected, never negative. */
    remaining_amount: string;
    status: InstallmentStatus;
    /** Derived (still open + past due) — no stored overdue status. */
    is_overdue: boolean;
    plan_total: string;
    plan_status: PaymentPlanStatus;
    reminder_7d_sent: boolean;
    reminder_1d_sent: boolean;
};

/** Collections-screen KPI totals (decimal strings). */
export type PaymentPlanStats = {
    overdue_count: number;
    overdue_total: string;
    due_soon_count: number;
    due_soon_total: string;
};

/** JSON:API list-state echoed by the controller (same shape as other filtered lists). */
export type PaymentPlanIndexQuery = {
    filter: {
        search: string;
        status: string;
        patient_id: number | null;
        due_after: string | null;
        due_before: string | null;
    };
    sort: string;
    per_page: number;
};

export type PaymentPlanIndexProps = {
    installments: PendingInstallment[];
    stats: PaymentPlanStats;
    query: PaymentPlanIndexQuery;
};

/** One installment in a patient's plan (patient detail). */
export type PatientPlanInstallment = {
    id: number;
    sequence: number;
    /** Y-m-d. */
    due_date: string;
    amount: string;
    /** Derived from the installment's transactions (refunds netted out). */
    collected_amount: string;
    /** amount − collected, never negative. */
    remaining_amount: string;
    status: InstallmentStatus;
    /** ISO 8601 or null. */
    paid_at: string | null;
    is_overdue: boolean;
};

/** A patient's payment plan with its schedule (patient detail). */
export type PatientPaymentPlan = {
    id: number;
    status: PaymentPlanStatus;
    total_amount: string;
    down_payment: string | null;
    installment_count: number;
    treatment_id: number | null;
    /** ISO 8601. */
    created_at: string;
    installments: PatientPlanInstallment[];
};

/** One editable installment row in the builder (clinic-local date; serialized to Y-m-d on submit). */
export type InstallmentRowForm = {
    sequence: number;
    due_date: Date | null;
    amount: number | null;
};

/**
 * Installment sub-state driven by the dynamic builder — shared by the treatment
 * PaymentSection (installment mode) and the standalone create dialog. `count` and
 * `start_date` are client-only generator params; only `installments` (+ down payment)
 * is sent to the server.
 */
export type InstallmentPlanForm = {
    count: number;
    start_date: Date | null;
    down_payment: number | null;
    down_payment_method: PaymentMethod | null;
    installments: InstallmentRowForm[];
};
