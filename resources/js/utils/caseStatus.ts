/**
 * Single source for case-status presentation so the tag color/label stay consistent across the
 * cases list, the case detail header and the patient-detail case sections. Pair with the
 * CaseStatusTag component; labels live under the generic `case.status.*` lang group.
 */
import type { CaseStatus } from '@/types/enums';

// Typed by CaseStatus → a forgotten/renamed case is a compile error.
export const CASE_STATUS_SEVERITY: Record<CaseStatus, string> = {
    open: 'success',
    suspended: 'warn',
    follow_up: 'info',
    closed: 'secondary',
};

export function caseStatusSeverity(status: CaseStatus): string {
    return CASE_STATUS_SEVERITY[status] ?? 'secondary';
}

/**
 * Statuses selectable in the index filter MultiSelect — order drives the option order.
 * Closed is last (terminal soft state).
 */
export const MVP_CASE_STATUSES: CaseStatus[] = [
    'open',
    'follow_up',
    'suspended',
    'closed',
];
