/** A doctor availability exception row, shaped by ScheduleExceptionResource. */
export type ScheduleException = {
    id: number;
    doctor_id: number;
    doctor_name: string;
    /** ISO 8601 UTC. */
    starts_at: string;
    /** ISO 8601 UTC. */
    ends_at: string;
    is_all_day: boolean;
    reason: string | null;
    created_by_name: string | null;
    /** Per-row gate: own-doctor || scheduleExceptions.manage. */
    can_delete: boolean;
};

/** Active-doctor option for the add-dialog Select and list filter. */
export type AvailabilityDoctorOption = {
    id: number;
    display_name: string;
};

export type AvailabilityIndexProps = {
    exceptions: ScheduleException[];
    doctors: AvailabilityDoctorOption[];
    /** The user's own doctors.id, for the self-only add path; null otherwise. */
    ownDoctorId: number | null;
    /** Active clinic timezone — for date/time pickers + list formatting. */
    timezone: string;
    /** Whether the list currently includes past exceptions (driven by the ?show_past param). */
    showPast: boolean;
    /** Upcoming view only: true when past exceptions exist, to offer the "show past" hint. */
    hasPast: boolean;
};

/** Payload posted to schedule-exceptions.store. Times are clinic-local naive strings. */
export type ScheduleExceptionFormData = {
    scope: 'doctor' | 'clinic';
    doctor_id: number | null;
    is_all_day: boolean;
    starts_at: string;
    ends_at: string;
    reason: string;
};
