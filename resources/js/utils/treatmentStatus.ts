/**
 * Single source for treatment-status presentation so the tag color/label stay consistent
 * across the Process screen, Show page and the patient "Tedavi Geçmişi" list. Pair with the
 * TreatmentStatusTag component; labels live under the generic `treatment.status.*` lang group.
 */
import type { TreatmentStatus } from '@/types/enums';

// Typed by TreatmentStatus → a forgotten/renamed case is a compile error.
export const TREATMENT_STATUS_SEVERITY: Record<TreatmentStatus, string> = {
    draft: 'warn',
    completed: 'success',
    voided: 'danger',
};

export function treatmentStatusSeverity(status: TreatmentStatus): string {
    return TREATMENT_STATUS_SEVERITY[status] ?? 'secondary';
}

/**
 * Statuses selectable in MVP filters — `voided` is unused in MVP, so it stays out of the
 * option list even though the server still accepts it. Order drives the MultiSelect order.
 */
export const MVP_TREATMENT_STATUSES: TreatmentStatus[] = ['draft', 'completed'];
