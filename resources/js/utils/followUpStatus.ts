/**
 * Single source for follow-up status presentation so colour + label stay consistent across the
 * case panel and the dashboard widget. Pair with FollowUpStatusTag. "Overdue" is a DERIVED
 * visual (open + past due), not a stored status, so it is applied on top of `open`.
 */
import type { FollowUpStatus } from '@/types/enums';

// Typed by FollowUpStatus → a forgotten/renamed case is a compile error.
export const FOLLOW_UP_STATUS_SEVERITY: Record<FollowUpStatus, string> = {
    open: 'info',
    done: 'success',
    cancelled: 'secondary',
};

export function followUpStatusSeverity(
    status: FollowUpStatus,
    isOverdue = false,
): string {
    if (status === 'open' && isOverdue) {
        return 'danger';
    }

    return FOLLOW_UP_STATUS_SEVERITY[status] ?? 'secondary';
}
