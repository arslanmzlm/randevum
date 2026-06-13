/**
 * Single source for transaction-status presentation so the tag color/label stay consistent
 * wherever a transaction is surfaced (patient Show + treatment Show balance lists). Pair with
 * the TransactionStatusTag component; labels live under the generic `transaction.status.*` group.
 */
import type { TransactionStatus } from '@/types/enums';

// Typed by TransactionStatus → a forgotten/renamed case is a compile error.
export const TRANSACTION_STATUS_SEVERITY: Record<TransactionStatus, string> = {
    pending: 'secondary',
    completed: 'success',
    partially_refunded: 'warn',
    refunded: 'danger',
};

export function transactionStatusSeverity(status: TransactionStatus): string {
    return TRANSACTION_STATUS_SEVERITY[status] ?? 'secondary';
}
