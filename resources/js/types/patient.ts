import type { InertiaForm } from '@inertiajs/vue3';
import type { PatientBalance, TransactionItem } from '@/types/balance';
import type { PatientCaseItem } from '@/types/case';
import type { AppointmentStatus } from '@/types/enums';
import type { SmsLogItem } from '@/types/smsLog';
import type { Paginated, TableState } from '@/types/table';
import type { PatientTreatmentHistoryItem } from '@/types/treatment';

export type PatientGender = 'male' | 'female' | 'other';

/** Canonical patient shape emitted by PatientResource (index + show + edit). */
export type Patient = {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    phone: string | null;
    contact_phone: string | null;
    email: string | null;
    /** ISO date (Y-m-d) or null. */
    birth_date: string | null;
    age: number | null;
    gender: PatientGender | null;
    notification_enabled: boolean;
    is_legacy: boolean;
    notes: string | null;
    /** ISO 8601 timestamp. */
    created_at: string;
    /** Most recent completed-treatment time (ISO 8601), or null. Only set on the list query. */
    last_visit_at?: string | null;
};

/**
 * Slim patient typeahead result from the `patients.search` JSON endpoint
 * (PatientSearchResource) — only what the quick-find option needs to render.
 */
export type PatientSearchResult = {
    id: number;
    full_name: string;
    phone: string | null;
};

/** Server-side list JSON:API state echoed back by the controller. */
export type PatientQuery = TableState<{
    gender: string;
    is_legacy: boolean | null;
}>;

export type PatientIndexProps = {
    patients: Paginated<Patient>;
    query: PatientQuery;
    /**
     * Remaining balance (billed − paid, positive = owes) per patient id, for the rows on the
     * current page. Absent when the user lacks `transactions.viewAny`; a patient missing from
     * the map is square.
     */
    balances?: Record<number, string>;
};

/**
 * Editable fields shared by the create and edit forms.
 * `birth_date` holds a Date for the PrimeVue DatePicker; it is serialized to a
 * Y-m-d string via `form.transform` before submission.
 */
export type PatientFormData = {
    first_name: string;
    last_name: string;
    phone: string;
    contact_phone: string;
    email: string;
    birth_date: Date | null;
    gender: PatientGender | null;
    notification_enabled: boolean;
    is_legacy: boolean;
    notes: string;
};

/** The create/edit Inertia form, shared with the field partials via provide/inject. */
export type PatientForm = InertiaForm<PatientFormData>;

/** Shared restore prompt payload (flash) when a phone matches a soft-deleted patient. */
export type RestorablePatient = {
    id: number;
    full_name: string;
};

/** One row of the patient's appointment history (own/all scoped, newest first). */
export type PatientAppointmentItem = {
    id: number;
    /** ISO 8601 timestamps. */
    starts_at: string;
    ends_at: string;
    status: AppointmentStatus;
    is_walk_in: boolean;
    doctor_name: string;
    service_name: string | null;
    appointment_type: { name: string; color: string } | null;
};

export type PatientShowProps = {
    patient: Patient;
    treatments: PatientTreatmentHistoryItem[];
    /** The patient's cases (own/all scoped); open vs closed split client-side. */
    cases: PatientCaseItem[];
    /** The patient's appointments; upcoming vs past split client-side. */
    appointments: PatientAppointmentItem[];
    /** The patient's recent SMS sends (newest first); shown with the page (not perm-gated). */
    smsLogs: SmsLogItem[];
    /** The user's own doctors.id; gates the retrospective-linking controls to own treatments. */
    ownDoctorId: number | null;
    /** Aggregate balance. Absent when the user lacks `transactions.viewAny` (server omits it). */
    balance?: PatientBalance;
    /** All the patient's transactions, newest first. Absent when denied (see `balance`). */
    transactions?: TransactionItem[];
};

/** Payload for the inline patient-level note quick-edit endpoint. */
export type PatientNotesFormData = {
    notes: string;
};

export type PatientEditProps = {
    patient: Patient;
};
