/**
 * Single source for stock-movement reason presentation (colour + option order) so the
 * history list filter, its row tags and the manual stock dialog stay in sync.
 */
import type { StockMovementReason } from '@/types/enums';

/** Every reason the ledger can hold — feeds the history-page filter. */
export const STOCK_MOVEMENT_REASONS: StockMovementReason[] = [
    'treatment_usage',
    'manual_adjustment',
    'return',
    'initial',
];

/** The reasons a user may pick in the manual stock dialog; the server rejects the others. */
export const MANUAL_STOCK_MOVEMENT_REASONS: StockMovementReason[] = [
    'manual_adjustment',
    'return',
];

// Typed by StockMovementReason → a forgotten/renamed case is a compile error.
export const STOCK_MOVEMENT_REASON_SEVERITY: Record<
    StockMovementReason,
    string
> = {
    treatment_usage: 'info',
    manual_adjustment: 'warn',
    return: 'success',
    initial: 'secondary',
};

export function stockMovementReasonSeverity(
    reason: StockMovementReason,
): string {
    return STOCK_MOVEMENT_REASON_SEVERITY[reason] ?? 'secondary';
}
