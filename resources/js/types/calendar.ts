import type { WorkingHours } from '@/types/clinic';
import type { AppointmentStatus } from '@/types/enums';

export type CalendarView = 'month' | 'week' | 'day';

/** Active-clinic doctor option for the per-doctor columns + doctor filter Select. */
export type CalendarDoctor = {
    id: number;
    display_name: string;
};

/** Props for the `calendar/Index` page (CalendarController@index). */
export type CalendarIndexProps = {
    doctors: CalendarDoctor[];
    /** The viewer's own doctors.id (default-selected column for a doctor user); null otherwise. */
    ownDoctorId: number | null;
    /** Clinic working hours JSON (drives the time-axis envelope + closed/break backdrop bands). */
    workingHours: WorkingHours | null;
    /** Clinic IANA timezone — the grid renders the server's clinic-local strings as-is. */
    timezone: string;
    /** clinics.default_slot_duration_minutes — the time-grid slot size. */
    defaultSlotDuration: number;
    defaultView: CalendarView;
    /** Branches the viewer may span; [] under 2 memberships or without appointments.viewAll. */
    clinics: { id: number; name: string }[];
};

/** One appointment event from `GET /calendar/events`. start/end are clinic-local 'YYYY-MM-DD HH:mm'. */
export type CalendarEventDto = {
    id: number;
    doctor_id: number;
    doctor_name: string;
    /** The row outlives its doctor: a soft-deleted one is named with a badge. */
    doctor_is_deleted: boolean;
    /** Owning branch — start/end are formatted in THIS clinic's timezone, not the active one. */
    clinic_id: number;
    clinic_name: string;
    title: string;
    /** Patient the popover title links to. */
    patient_id: number;
    /** Soft-deleted patient: the popover shows the name unlinked, with a badge. */
    patient_is_deleted: boolean;
    start: string;
    end: string;
    /** ISO 8601 UTC instant of `start` — what the past/future action rules compare against. */
    starts_at_utc: string;
    status: AppointmentStatus;
    is_walk_in: boolean;
    service_name: string | null;
    type_name: string | null;
    type_color: string | null;
    /** The 1:1 treatment id when one exists — drives "start treatment" vs "resume draft". */
    treatment_id: number | null;
};

/** A schedule-exception/closed block from `GET /calendar/events`, rendered as a background event. */
export type CalendarExceptionDto = {
    id: number;
    doctor_id: number;
    doctor_name: string;
    /** The row outlives its doctor: a soft-deleted one is named with a badge. */
    doctor_is_deleted: boolean;
    start: string;
    end: string;
    reason: string | null;
};

export type CalendarEventsResponse = {
    data: CalendarEventDto[];
    exceptions: CalendarExceptionDto[];
};

/**
 * Appointment count for one day and one dimension — the month view's summary chip. The dimension
 * is the doctor by default and the branch in multi-branch mode, so the chip carries a generic
 * `key`/`label` pair instead of doctor-specific fields.
 */
export type CalendarDaySummary = {
    date: string;
    key: number;
    label: string;
    count: number;
};

/** A muted, non-interactive backdrop band: before-open / lunch break / after-close / closed day. */
export type CalendarClosedBand = {
    fromMin: number;
    toMin: number;
    label?: string;
};

/** One column of the time grid — a day (week view) or a doctor (day view, all doctors). */
export type CalendarColumn = {
    key: string;
    date: Date;
    /** Header label: weekday short (week) or doctor name (day); '' hides the header row. */
    label: string;
    /** Secondary header line — the day-of-month number in week view. */
    sublabel?: string;
    /** Per-doctor accent for the header dot (day-per-doctor columns); undefined = none. */
    tintColor?: string;
    isToday: boolean;
    events: CalendarEventDto[];
    exceptions: CalendarExceptionDto[];
    closedBands: CalendarClosedBand[];
    /** Column holds more than one doctor's leave → name each block (a doctor column doesn't). */
    namedLeave?: boolean;
    /** doctor id → accent colour; set when the column mixes doctors (week view). */
    doctorColors?: Record<number, string>;
};
