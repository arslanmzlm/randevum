/**
 * Single source for appointment-status presentation so colors stay consistent
 * everywhere (day panel, list, calendar). Pair with the AppointmentStatusTag
 * component for the label + Tag; import the map directly when only the color is
 * needed (e.g. calendar event coloring). Cancelled is included for completeness
 * even though some surfaces exclude it server-side.
 */
import type { AppointmentStatus } from '@/types/enums';

// Typed by AppointmentStatus → a forgotten/renamed case is a compile error.
export const APPOINTMENT_STATUS_SEVERITY: Record<AppointmentStatus, string> = {
    pending: 'warn',
    confirmed: 'info',
    rescheduled: 'secondary',
    arrived: 'info',
    completed: 'success',
    cancelled: 'danger',
    no_show: 'danger',
};

export function appointmentStatusSeverity(status: AppointmentStatus): string {
    return APPOINTMENT_STATUS_SEVERITY[status] ?? 'secondary';
}

// Concrete colour per status for surfaces that paint (calendar chip border/fill) rather than use a
// PrimeVue severity Tag. Aligned to the severities above but with distinct hues so confirmed vs
// arrived read apart at a glance. The appointment-type colour is shown separately (a small dot).
export const APPOINTMENT_STATUS_COLOR: Record<AppointmentStatus, string> = {
    pending: 'var(--p-amber-500)',
    confirmed: 'var(--p-blue-500)',
    rescheduled: 'var(--p-violet-500)',
    arrived: 'var(--p-teal-500)',
    completed: 'var(--p-green-500)',
    cancelled: 'var(--p-red-500)',
    no_show: 'var(--p-rose-500)',
};

export function appointmentStatusColor(status: AppointmentStatus): string {
    return APPOINTMENT_STATUS_COLOR[status] ?? 'var(--p-surface-400)';
}

/**
 * Statuses selectable in MVP filters — `pending` is Faz 2, so excluded.
 * Order drives the filter MultiSelect option order.
 */
export const MVP_APPOINTMENT_STATUSES: AppointmentStatus[] = [
    'confirmed',
    'rescheduled',
    'arrived',
    'completed',
    'cancelled',
    'no_show',
];

/**
 * Default calendar visibility — all MVP statuses except `cancelled` and `no_show`
 * (both opt-in via the filter, keeping the grid focused on active appointments).
 */
export const DEFAULT_CALENDAR_STATUSES: AppointmentStatus[] =
    MVP_APPOINTMENT_STATUSES.filter(
        (status) => status !== 'cancelled' && status !== 'no_show',
    );
