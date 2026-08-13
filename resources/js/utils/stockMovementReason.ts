/**
 * Single source for stock-movement reason presentation (colour + option order) and for the
 * direction rule each reason carries, so the history list filter, its row tags and the
 * manual stock dialog stay in sync with `App\Enums\StockMovementReason`.
 */
import type { StockMovementReason } from '@/types/enums';

/** Every reason the ledger can hold — feeds the history-page filter. */
export const STOCK_MOVEMENT_REASONS: StockMovementReason[] = [
    'treatment_usage',
    'treatment_void',
    'initial',
    'stock_in',
    'patient_return',
    'transfer_in',
    'supplier_return',
    'wastage',
    'transfer_out',
    'count_correction',
    // Legacy — no longer written, kept so old rows stay filterable.
    'manual_adjustment',
    'return',
];

/**
 * Which way a reason may move stock: 1 = in, -1 = out, null = either direction.
 * Mirrors `StockMovementReason::sign()`; the server is the gate, this only drives the UI.
 */
export const STOCK_MOVEMENT_REASON_SIGN: Record<
    StockMovementReason,
    1 | -1 | null
> = {
    treatment_usage: -1,
    treatment_void: 1,
    initial: null,
    stock_in: 1,
    patient_return: 1,
    transfer_in: 1,
    supplier_return: -1,
    wastage: -1,
    transfer_out: -1,
    count_correction: null,
    manual_adjustment: null,
    return: 1,
};

/** The reasons a user may pick in the manual stock dialog; the server rejects the others. */
export const MANUAL_STOCK_MOVEMENT_REASONS: StockMovementReason[] = [
    'stock_in',
    'patient_return',
    'transfer_in',
    'supplier_return',
    'wastage',
    'transfer_out',
    'count_correction',
];

/**
 * Movement mode derives the sign from the reason, so the one reason without a fixed
 * direction (count correction) belongs to count mode only.
 */
export const MOVEMENT_MODE_STOCK_REASONS: StockMovementReason[] =
    MANUAL_STOCK_MOVEMENT_REASONS.filter(
        (reason) => STOCK_MOVEMENT_REASON_SIGN[reason] !== null,
    );

// Typed by StockMovementReason → a forgotten/renamed case is a compile error.
export const STOCK_MOVEMENT_REASON_SEVERITY: Record<
    StockMovementReason,
    string
> = {
    treatment_usage: 'info',
    // A void restock reverses a usage row, so it reads as the treatment's danger colour
    // rather than the plain inbound green.
    treatment_void: 'danger',
    initial: 'secondary',
    stock_in: 'success',
    patient_return: 'success',
    transfer_in: 'info',
    supplier_return: 'warn',
    wastage: 'danger',
    transfer_out: 'warn',
    // Bookkeeping rather than a real movement, so it gets the opening row's neutral tone.
    count_correction: 'secondary',
    manual_adjustment: 'warn',
    return: 'success',
};

export function stockMovementReasonSeverity(
    reason: StockMovementReason,
): string {
    return STOCK_MOVEMENT_REASON_SEVERITY[reason] ?? 'secondary';
}
