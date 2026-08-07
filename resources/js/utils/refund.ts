import type { RefundTarget } from '@/types/balance';
import type { TransactionStatus } from '@/types/enums';

/**
 * Whether a row can still be reversed, in one place: the patient balance list and the manual
 * income list must agree on what "refundable" means, and the server enforces the same rule in
 * RefundService (counter-entries and fully-refunded rows have no remaining).
 */
export function isRefundable(
    row: RefundTarget & { status: TransactionStatus },
): boolean {
    return (
        (row.status === 'completed' || row.status === 'partially_refunded') &&
        Number(row.refundable_amount) > 0
    );
}
