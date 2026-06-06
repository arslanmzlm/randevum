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
