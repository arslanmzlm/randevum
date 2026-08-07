import type { StockMovementReason } from '@/types/enums';
import type { Product } from '@/types/product';
import type { Paginated, TableState } from '@/types/table';

/** One ledger row (mirrors StockMovementResource) on the per-product history page. */
export type StockMovement = {
    id: number;
    /** Signed: negative = out, positive = in. Never 0. */
    quantity: number;
    /** `products.current_stock` right after this movement; may be negative. */
    balance_after: number;
    reason: StockMovementReason;
    note: string | null;
    /** ISO 8601 UTC timestamp; formatted client-side via useDateTime(). */
    created_at: string;
    /** Null for seeded/system rows. */
    created_by_name: string | null;
    /** Set only for treatment_usage rows. */
    treatment_id: number | null;
    /** Patient behind the linked treatment; null when the row has no treatment. */
    patient_name: string | null;
};

/** Server-side list JSON:API state echoed back by the movement index controller. */
export type StockMovementQuery = TableState<{
    /** Comma-separated reason values (multi-value enum filter). */
    reason: string;
    start_date: string;
    end_date: string;
}>;

export type StockMovementIndexProps = {
    product: Product;
    movements: Paginated<StockMovement>;
    query: StockMovementQuery;
};
