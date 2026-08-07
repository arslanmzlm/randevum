import type { PaymentMethod, TransactionStatus } from '@/types/enums';

/**
 * The minimum a row must expose for `RefundDialog` to reverse it — so any transaction-backed list
 * (patient balance, manual income) can reuse the dialog without a near-duplicate.
 */
export type RefundTarget = {
    id: number;
    /** Decimal string — remaining refundable for an original payment; `"0"` for counter-entries and fully-refunded rows. */
    refundable_amount: string;
};

/**
 * One transaction (collection/refund) row — the backend↔frontend seam shared by both
 * `treatments/Show` (treatment-bound) and `patients/Show` (all patient transactions).
 * `amount` is a decimal string (negative ⇒ refund); `treatment_id` null ⇒ standalone payment.
 */
export type TransactionItem = RefundTarget & {
    /** ISO 8601. */
    paid_at: string;
    payment_method: PaymentMethod;
    /** Decimal string; may be negative for a refund. */
    amount: string;
    note: string | null;
    status: TransactionStatus;
    /** null ⇒ standalone payment (not tied to a treatment). */
    treatment_id: number | null;
    /** Set only on refund counter-entries → the original payment they reverse; null otherwise. */
    original_transaction_id: number | null;
};

/**
 * Aggregate patient balance — always derived server-side, never stored. `remaining` may be
 * negative ⇒ the patient is in credit (overpaid). All values are decimal strings.
 */
export type PatientBalance = {
    total: string;
    paid: string;
    remaining: string;
};
