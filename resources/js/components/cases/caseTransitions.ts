/**
 * Case status-transition presentation — the action label key + button/confirm severity per
 * target status. Kept out of the page so the header buttons and the confirm dialog share one
 * source. No reactivity needed; mirrors utils/appointmentStatus.ts. `transitionLabel` takes the
 * `t` from the caller's useI18n() so the strings resolve through the same i18n instance.
 */
import type { CaseStatus } from '@/types/enums';

export const CASE_ACTION_KEY: Record<CaseStatus, string> = {
    open: 'reopen',
    suspended: 'suspend',
    follow_up: 'follow_up',
    closed: 'close',
};

export const CASE_ACTION_SEVERITY: Record<CaseStatus, string> = {
    open: 'success',
    suspended: 'warn',
    follow_up: 'info',
    closed: 'danger',
};

export function caseTransitionLabel(
    t: (key: string) => string,
    target: CaseStatus,
): string {
    return t(`case.actions.${CASE_ACTION_KEY[target]}`);
}
