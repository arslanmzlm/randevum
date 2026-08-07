/**
 * Single source for installment-status presentation so colour + label stay consistent
 * across the collections screen and the patient plan card. Pair with the
 * InstallmentStatusTag component. Overdue is a DERIVED visual (still open + past due) — not
 * a stored status — so it is applied on top of the open statuses via the `isOverdue` flag.
 */
import type { InstallmentStatus, PaymentPlanStatus } from '@/types/enums';

// Typed by InstallmentStatus → a forgotten/renamed case is a compile error.
export const INSTALLMENT_STATUS_SEVERITY: Record<InstallmentStatus, string> = {
    pending: 'warn',
    partially_paid: 'info',
    paid: 'success',
    cancelled: 'secondary',
};

/** Statuses that still carry a receivable — both take the overdue treatment when past due. */
const OPEN_STATUSES: InstallmentStatus[] = ['pending', 'partially_paid'];

/** Still collectible: a partially collected installment stays open until its remaining is zero. */
export function isInstallmentOpen(status: InstallmentStatus): boolean {
    return OPEN_STATUSES.includes(status);
}

// Plan-level status presentation (labels under `payment_plan.plan_status.*`).
export const PAYMENT_PLAN_STATUS_SEVERITY: Record<PaymentPlanStatus, string> = {
    active: 'info',
    completed: 'success',
    cancelled: 'secondary',
};

export function paymentPlanStatusSeverity(status: PaymentPlanStatus): string {
    return PAYMENT_PLAN_STATUS_SEVERITY[status] ?? 'secondary';
}

export function installmentSeverity(
    status: InstallmentStatus,
    isOverdue: boolean,
): string {
    if (isOverdue && OPEN_STATUSES.includes(status)) {
        return 'danger';
    }

    return INSTALLMENT_STATUS_SEVERITY[status] ?? 'secondary';
}

/** Label-key suffix under `payment_plan.installment_status.*` (overdue gets its own label). */
export function installmentLabelKey(
    status: InstallmentStatus,
    isOverdue: boolean,
): string {
    if (isOverdue && OPEN_STATUSES.includes(status)) {
        return 'overdue';
    }

    return status;
}
