import type { InertiaForm } from '@inertiajs/vue3';
import type { AppointmentStatus, AvailabilityReason } from '@/types/enums';
import type { PatientSearchResult } from '@/types/patient';
import type { Paginated, TableState } from '@/types/table';
import type { OccurrenceDraft } from '@/utils/followUpOccurrences';

// Re-export so existing imports from '@/types/appointment' keep working.
export type { AppointmentStatus, AvailabilityReason };

/** One row of the appointment index list (mirrors AppointmentResource). */
export type AppointmentListItem = {
    id: number;
    patient_id: number;
    patient_name: string;
    doctor_id: number;
    doctor_name: string;
    service_name: string | null;
    appointment_type: { name: string; color: string } | null;
    status: AppointmentStatus;
    is_walk_in: boolean;
    /** ISO 8601 UTC timestamp. */
    starts_at: string;
    /** ISO 8601 UTC timestamp. */
    ends_at: string;
    /** The 1:1 treatment id when one exists — drives "start treatment" vs "resume draft". */
    treatment_id: number | null;
};

/**
 * One upcoming-appointment row for the header quick-access widget. Shared both as the
 * `upcomingAppointments` Inertia prop and the `GET /appointments/upcoming` JSON payload
 * (one source of truth). Mirrors AppointmentListItem minus the list-only fields.
 */
export type UpcomingAppointmentDto = {
    id: number;
    patient_id: number;
    patient_name: string;
    doctor_id: number;
    doctor_name: string;
    service_name: string | null;
    appointment_type: { name: string; color: string } | null;
    /** Only `confirmed` | `rescheduled` are emitted, but kept broad for reuse. */
    status: AppointmentStatus;
    is_walk_in: boolean;
    /** ISO 8601 UTC timestamp; formatted client-side via useDateTime(). */
    starts_at: string;
};

/** Server-side list JSON:API state echoed back by the appointment index controller. */
export type AppointmentListQuery = TableState<{
    status: string;
    doctor_id: number | null;
    start_date: string;
    end_date: string;
}>;

export type AppointmentIndexProps = {
    appointments: Paginated<AppointmentListItem>;
    doctors: AppointmentDoctorOption[];
    query: AppointmentListQuery;
    /** The user's own doctors.id; gates row actions to own appointments when they lack viewAll. */
    ownDoctorId: number | null;
};

/** Active-clinic doctor option for the create-appointment doctor select. */
export type AppointmentDoctorOption = {
    id: number;
    display_name: string;
};

/** Active service option — selecting one drives the default slot duration. */
export type AppointmentServiceOption = {
    id: number;
    name: string;
    duration_minutes: number;
    price: string;
};

/** Active appointment-type option — drives the slot duration (and calendar color) when no service is chosen. */
export type AppointmentTypeOption = {
    id: number;
    name: string;
    color: string;
    default_duration_minutes: number;
};

/** A single weekday's clinic working-hours window (mirrors clinics.working_hours JSON). */
export type WorkingHoursDay = {
    closed?: true;
    open?: string;
    close?: string;
    break?: [string, string];
};

export type WorkingHours = Record<string, WorkingHoursDay>;

/** Props for the `appointments/Create` page (AppointmentController@create). */
export type AppointmentCreateProps = {
    doctors: AppointmentDoctorOption[];
    services: AppointmentServiceOption[];
    appointmentTypes: AppointmentTypeOption[];
    defaultSlotDuration: number;
    workingHours: WorkingHours;
    timezone: string;
    preselectedPatient: PatientSearchResult | null;
    /** The user's own doctors.id (auto-selected); null when they have no doctor profile. */
    ownDoctorId: number | null;
};

/** The fixed (read-only) patient + current slot of the appointment being rescheduled. */
export type EditAppointment = {
    id: number;
    patient: { id: number; full_name: string; phone: string | null };
    doctor_id: number;
    service_id: number | null;
    appointment_type_id: number | null;
    /** (ends_at − starts_at) in minutes — prefills the duration field. */
    duration_minutes: number;
    /** ISO 8601 UTC timestamp; converted to clinic-local date + time for the form. */
    starts_at: string;
    status: AppointmentStatus;
    is_walk_in: boolean;
};

/** Props for the `appointments/Edit` page (AppointmentController@edit). */
export type AppointmentEditProps = {
    doctors: AppointmentDoctorOption[];
    services: AppointmentServiceOption[];
    appointmentTypes: AppointmentTypeOption[];
    defaultSlotDuration: number;
    workingHours: WorkingHours;
    timezone: string;
    /** The user's own doctors.id (locks the doctor select when they can't assign others). */
    ownDoctorId: number | null;
    appointment: EditAppointment;
};

/** Minimal new-patient quick-create fields, posted when patient_mode is 'new'. */
export type NewPatientFormData = {
    first_name: string;
    last_name: string;
    phone: string;
    email: string;
};

/**
 * Editable create-appointment fields. `date` holds a Date from the inline DatePicker and
 * `time` an "HH:mm" string from the masked input; `form.transform` derives `patient_mode`
 * (existing vs new) and combines date + time into a clinic-local `starts_at` ISO string.
 */
export type AppointmentFormData = {
    patient_id: number | null;
    new_patient: NewPatientFormData;
    doctor_id: number | null;
    service_id: number | null;
    appointment_type_id: number | null;
    date: Date | null;
    time: string;
    duration_minutes: number | null;
    is_walk_in: boolean;
};

/** The create-appointment Inertia form, passed to the field partial components. */
export type AppointmentForm = InertiaForm<AppointmentFormData>;

/** Response of the `appointments.availability` pre-check (reason null ⇔ available). */
export type AvailabilityCheckResponse = {
    available: boolean;
    reason: AvailabilityReason | null;
};

/** Props for the `appointments/BulkCancel` page (AppointmentController@bulkCancelPage). */
export type BulkCancelProps = {
    /** Active-clinic doctors; `[]` when the user lacks `appointments.viewAll`. */
    doctors: AppointmentDoctorOption[];
    /** Clinic timezone — the date pickers select clinic-local days. */
    timezone: string;
    /** The user's own doctors.id; UX hint when they cannot view all doctors. */
    ownDoctorId: number | null;
};

/** One row of the bulk-cancel preview probe (`appointments.bulk-cancel.preview`). */
export type BulkCancelPreviewRow = {
    id: number;
    /** ISO 8601 UTC timestamp; formatted client-side via useDateTime. */
    starts_at: string;
    patient_name: string;
    doctor_name: string;
    service_name: string | null;
    status: AppointmentStatus;
    /** Patient has a phone → will receive the cancellation SMS once 1.16/1.17 lands. */
    has_phone: boolean;
};

/** One editable occurrence in the bulk-booking flow (same draft shape the generator emits). */
export type BulkOccurrenceForm = OccurrenceDraft;

/**
 * The bulk-appointment form: one patient (existing or new) + doctor + optional service, plus the
 * generated/editable occurrence list. Generator params (start/count/interval/seed) live in the
 * generator component, not here — only what is submitted is on the form.
 */
export type BulkAppointmentFormData = {
    patient_id: number | null;
    new_patient: NewPatientFormData;
    doctor_id: number | null;
    service_id: number | null;
    occurrences: BulkOccurrenceForm[];
};

/** The bulk-appointment Inertia form, shared with the field partials via provide/inject. */
export type BulkAppointmentForm = InertiaForm<BulkAppointmentFormData>;

/** The post-book report (session-flashed) — created count + skipped clinic-local slot strings. */
export type BulkAppointmentResult = {
    created: number;
    /** 'd.m.Y H:i' clinic-local strings for the slots that conflicted and were skipped. */
    skipped: string[];
};

/** Props for the `appointments/BulkCreate` page (AppointmentController@bulkCreatePage). */
export type BulkCreateProps = {
    doctors: AppointmentDoctorOption[];
    services: AppointmentServiceOption[];
    appointmentTypes: AppointmentTypeOption[];
    defaultSlotDuration: number;
    timezone: string;
    preselectedPatient: PatientSearchResult | null;
    /** The user's own doctors.id (auto-selected); null when they have no doctor profile. */
    ownDoctorId: number | null;
    /** Post-book report; null on a fresh GET, populated on the redirect-back render. */
    result: BulkAppointmentResult | null;
};

/** POST body of the bulk-booking conflict pre-check (`appointments.bulk-create.precheck`). */
export type BulkPrecheckPayload = {
    doctor_id: number | null;
    service_id: number | null;
    occurrences: Array<{
        starts_at: string | null;
        duration_minutes: number | null;
        appointment_type_id: number | null;
    }>;
};

/** Response of the bulk pre-check: clinic-local 'd.m.Y H:i' strings for the slots that would be skipped. */
export type BulkPrecheckResponse = {
    conflicts: string[];
};

/** One row of the selected-day panel (`appointments.day-schedule`). */
export type DayScheduleEntry = {
    id: number;
    start_time: string;
    end_time: string;
    status: AppointmentStatus;
    is_walk_in: boolean;
    patient_name: string;
    /** What the patient is booked for; null when no service was chosen. */
    service_name: string | null;
    /** The booked appointment type (name + calendar color); null when none was chosen. */
    appointment_type: { name: string; color: string } | null;
};
