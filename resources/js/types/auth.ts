export type User = {
    id: number;
    first_name: string;
    last_name: string;
    /** Computed full name (first_name + last_name). */
    name: string;
    email: string;
    phone: string | null;
    avatar?: string;
    email_verified_at: string | null;
    phone_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    /** Null when the request is unauthenticated (e.g. login page). */
    user: User | null;
    /** True when the current user has a doctor profile in the active clinic. */
    isDoctor?: boolean;
    /** Owner: may view/edit the clinic profile. */
    canManageClinic?: boolean;
    /** Owner or manager: may add/edit/remove doctors. */
    canManageDoctors?: boolean;
    /** Owner, manager or doctor: may view the service catalog. */
    canViewServices?: boolean;
    /** Owner, manager or doctor: may view the product catalog. */
    canViewProducts?: boolean;
    /** Owner or manager: may manage clinic appointment types. */
    canManageAppointmentTypes?: boolean;
    /** Clinic staff with patients.viewAny: may view the patient list. */
    canViewPatients?: boolean;
    /** Clinic staff with scheduleExceptions.viewAny: may view the availability screen. */
    canViewAvailability?: boolean;
    /** Clinic staff with appointments.create: may open the create-appointment form. */
    canCreateAppointments?: boolean;
};
