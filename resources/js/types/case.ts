import type { CaseStatus, TreatmentStatus } from '@/types/enums';
import type { CaseFollowUpItem, FollowUpTypeOption } from '@/types/followUp';
import type { CaseMediaItem } from '@/types/media';
import type { Paginated, TableState } from '@/types/table';

/** One row of the cases index list (mirrors CaseListResource). */
export type CaseListItem = {
    id: number;
    title: string;
    status: CaseStatus;
    patient: { id: number; full_name: string; is_deleted: boolean };
    doctor: { id: number; display_name: string };
    treatments_count: number;
    /** ISO 8601 UTC timestamp. */
    opened_at: string;
    /** ISO 8601 UTC timestamp or null. */
    closed_at: string | null;
    /** ISO date (Y-m-d) or null. */
    follow_up_date: string | null;
};

/** Active-clinic doctor option for the index doctor filter (only present with cases.viewAll). */
export type CaseDoctorOption = {
    id: number;
    display_name: string;
};

/** Server-side list JSON:API state echoed back by the case index controller. */
export type CaseListQuery = TableState<{
    status: string;
    doctor_id: number | null;
}>;

export type CaseIndexProps = {
    cases: Paginated<CaseListItem>;
    /** Empty unless the user has cases.viewAll. */
    doctors: CaseDoctorOption[];
    query: CaseListQuery;
    /** The user's own doctors.id; gates row navigation ownership when they lack viewAll. */
    ownDoctorId: number | null;
};

/** A linked treatment shown on the case detail page. */
export type CaseTreatmentItem = {
    id: number;
    title: string | null;
    status: TreatmentStatus;
    completed_at: string | null;
    total_amount: string;
    /** Vertical detail fields — shown inline so the case reads without opening each treatment. */
    complaint: string | null;
    diagnosis: string | null;
    treatment_process: string | null;
};

/** Full case detail (mirrors CaseShowResource). */
export type CaseDetail = {
    id: number;
    title: string;
    status: CaseStatus;
    notes: string | null;
    opened_at: string;
    closed_at: string | null;
    suspended_at: string | null;
    patient: { id: number; full_name: string; is_deleted: boolean };
    doctor: { id: number; display_name: string };
    treatments: CaseTreatmentItem[];
    /** Open rows first (due_date asc), then done/cancelled newest-completed-first. */
    follow_ups: CaseFollowUpItem[];
    /** Read-only rollup of media across all the case's treatments. Absent when the
     *  user lacks `treatments.media.view` (server omits it). No upload here. */
    media?: CaseMediaItem[];
};

/** An ungrouped completed treatment available to link into the case. */
export type UngroupedTreatmentItem = {
    id: number;
    title: string | null;
    completed_at: string | null;
    doctor_id: number;
    doctor_name: string;
    total_amount: string;
};

export type CaseShowProps = {
    case: CaseDetail;
    /** Next CaseStatus values reachable from the current state. */
    allowedTransitions: CaseStatus[];
    ungroupedTreatments: UngroupedTreatmentItem[];
    /** Within config('platform.edit_windows.case') of opened_at. */
    canEditTitle: boolean;
    /** Mirrors CasePolicy::update (viewAll, or ownership) — server-computed, not re-derived client-side. */
    canManage: boolean;
    /** Mirrors FollowUpPolicy::complete's ownership formula at case granularity. */
    canCompleteFollowUp: boolean;
    /** Active follow-up types for the add + → follow_up transition dialogs. */
    followUpTypes: FollowUpTypeOption[];
};

/** Payload for the inline case-notes quick-edit endpoint. */
export type CaseNotesFormData = {
    notes: string;
};

/** Payload of the → follow_up case-status transition (creates a follow-up row server-side). */
export type CaseFollowUpFormData = {
    follow_up_type_id: number | null;
    /** Holds a Date for the PrimeVue DatePicker; serialized to Y-m-d before submit. */
    due_date: Date | null;
    note: string;
};

/** Payload for the inline case-title quick-edit endpoint. */
export type CaseTitleFormData = {
    title: string;
};

/** One of a patient's cases, shown on the patient detail page (PatientController@show). */
export type PatientCaseItem = {
    id: number;
    title: string;
    status: CaseStatus;
    treatments_count: number;
    /** ISO 8601 UTC timestamp. */
    opened_at: string;
    /** ISO date (Y-m-d) or null. */
    follow_up_date: string | null;
};
