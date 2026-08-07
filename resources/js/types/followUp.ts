import type { CaseStatus, FollowUpStatus } from '@/types/enums';

/** Active follow-up type, as offered in the create / transition dialogs. */
export type FollowUpTypeOption = {
    id: number;
    name: string;
};

/** One case offered by the `follow-ups.cases` JSON endpoint for a picked patient. */
export type FollowUpCaseOption = {
    id: number;
    title: string;
    status: CaseStatus;
};

/** One follow-up row of the case-detail panel (FollowUpService::forCase). */
export type CaseFollowUpItem = {
    id: number;
    status: FollowUpStatus;
    /** null when the type row was deleted — rendered as "—". */
    type: FollowUpTypeOption | null;
    /** ISO date (Y-m-d), tz-less calendar date. */
    due_date: string;
    note: string | null;
    /** ISO 8601 UTC timestamp. */
    completed_at: string | null;
    /** Full name of the user who completed it. */
    completed_by: string | null;
    result_note: string | null;
    /** Open and due_date < today (clinic tz). */
    is_overdue: boolean;
};

/** Payload of the manual create-follow-up dialog. */
export type FollowUpFormData = {
    patient_id: number | null;
    case_id: number | null;
    follow_up_type_id: number | null;
    /** Holds a Date for the PrimeVue DatePicker; serialized to Y-m-d before submit. */
    due_date: Date | null;
    note: string;
};

/** Payload of the completion dialog — the result note is optional. */
export type FollowUpCompleteFormData = {
    result_note: string;
};
