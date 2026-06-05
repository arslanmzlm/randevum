import type { PatientSearchResult } from '@/types/patient';

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
    defaultSlotDuration: number;
    workingHours: WorkingHours;
    timezone: string;
    preselectedPatient: PatientSearchResult | null;
    /** appointments.assignDoctor — when false the doctor select locks to ownDoctorId. */
    canAssignDoctor: boolean;
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
    date: Date | null;
    time: string;
    duration_minutes: number | null;
    is_walk_in: boolean;
};
