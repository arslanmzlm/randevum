import type { RestorablePatient } from '@/types/patient';

/** One flash toast queued server-side via `App\Modules\Core\Support\Toast`. */
export type FlashToast = {
    severity: 'success' | 'info' | 'warn' | 'error';
    summary: string;
    detail: string | null;
    life: number;
};

/** Shared `flash` page prop (`HandleInertiaRequests`). */
export type Flash = {
    toasts: FlashToast[];
    /** One-time nudge to change an admin-set password on first-ever login. */
    password_reminder: boolean;
    /** Set when a patient store hits a phone owned by a soft-deleted patient. */
    restorable_patient: RestorablePatient | null;
};
