import type { ProductLineForm, ServiceLineForm } from '@/types/treatment';

// Client-side mirror of the server's app-layer money math — preview only, server recomputes.

export function lineSubtotal(line: ServiceLineForm | ProductLineForm): number {
    const gross = (line.quantity || 0) * (line.unit_price ?? 0);

    return Math.max(0, gross - (line.discount_amount ?? 0));
}

/** Effective line discounts (each capped at its line's gross — the subtotal clamp at 0). */
export function lineDiscountTotal(
    services: ServiceLineForm[],
    products: ProductLineForm[],
): number {
    return [...services, ...products].reduce((sum, line) => {
        const gross = (line.quantity || 0) * (line.unit_price ?? 0);

        return sum + Math.min(line.discount_amount ?? 0, gross);
    }, 0);
}

export function treatmentSubtotal(
    services: ServiceLineForm[],
    products: ProductLineForm[],
): number {
    return [...services, ...products].reduce(
        (sum, line) => sum + lineSubtotal(line),
        0,
    );
}

export function treatmentTotal(
    services: ServiceLineForm[],
    products: ProductLineForm[],
    discountAmount: number | null,
): number {
    return Math.max(
        0,
        treatmentSubtotal(services, products) - (discountAmount ?? 0),
    );
}
