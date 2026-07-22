// Pure installment-schedule math for the taksit builder: split a remaining amount into N equal
// monthly rows from a start date. Mirrors the follow-up occurrence generator (utils/
// followUpOccurrences.ts) but for money instalments. Kept free of Vue/form state — callers own the
// reactive list. Money is split in integer cents so Σ rows == remaining exactly (no float drift);
// the rounding remainder lands on the first row.
import { addMonths, startOfDay } from 'date-fns';

/** One generated installment row (clinic-local due date + amount), before serialization. */
export type InstallmentDraft = {
    sequence: number;
    due_date: Date;
    amount: number;
};

/** Owner-brief default: first installment falls one month from today. */
export function defaultInstallmentStart(): Date {
    return addMonths(startOfDay(new Date()), 1);
}

/**
 * Build `count` equal monthly installments covering `total − downPayment`, starting at `startDate`
 * (row 1 = startDate, then +1 month each). Returns an empty list until a valid start + count exist.
 * `count` is clamped to 1..60 (the server cap). The first row absorbs the cents remainder so the
 * rows sum exactly to the remaining amount.
 */
export function buildInstallments(params: {
    total: number;
    downPayment: number;
    count: number;
    startDate: Date | null;
}): InstallmentDraft[] {
    const { total, downPayment, startDate } = params;

    if (!startDate) {
        return [];
    }

    const count = Math.min(60, Math.max(1, Math.trunc(params.count || 0)));

    if (count < 1) {
        return [];
    }

    const remainingCents = Math.max(
        0,
        Math.round((total - (downPayment || 0)) * 100),
    );
    const base = Math.floor(remainingCents / count);
    const remainder = remainingCents - base * count;

    const rows: InstallmentDraft[] = [];

    for (let i = 0; i < count; i++) {
        const cents = base + (i === 0 ? remainder : 0);

        rows.push({
            sequence: i + 1,
            due_date: addMonths(startDate, i),
            amount: cents / 100,
        });
    }

    return rows;
}
