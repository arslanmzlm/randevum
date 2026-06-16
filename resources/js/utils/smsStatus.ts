/**
 * Single source for SMS-status presentation so colors/labels stay consistent across
 * the clinic-wide SMS log list and the patient communication-history section. Pair
 * with the SmsStatusTag component for the label + Tag; import the map directly when
 * only the severity is needed.
 */
import type { SmsStatus } from '@/types/enums';

// Typed by SmsStatus → a forgotten/renamed case is a compile error.
export const SMS_STATUS_SEVERITY: Record<SmsStatus, string> = {
    queued: 'info',
    sent: 'success',
    failed: 'danger',
    skipped: 'warn',
};

export function smsStatusSeverity(status: SmsStatus): string {
    return SMS_STATUS_SEVERITY[status] ?? 'secondary';
}

/** Order drives the status filter Select option order. */
export const SMS_STATUSES: SmsStatus[] = [
    'queued',
    'sent',
    'failed',
    'skipped',
];
