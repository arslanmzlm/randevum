import type { InertiaForm } from '@inertiajs/vue3';
import type { AppointmentStatus, AvailabilityReason } from '@/types/enums';
import type { PatientSearchResult } from '@/types/patient';
import type { Paginated, TableState } from '@/types/table';

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
