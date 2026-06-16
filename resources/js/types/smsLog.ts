import type { SmsStatus, SmsType } from '@/types/enums';
import type { Paginated, TableState } from '@/types/table';

/**
 * One SMS-log row (mirrors SmsLogResource), shared by the clinic-wide list and the
 * patient communication-history section.
 */
export type SmsLogItem = {
    id: number;
    type: SmsType;
    status: SmsStatus;
    phone: string | null;
    /** Patient full name when the send is linked to a patient, else null (raw phone shown). */
    patient_name: string | null;
    patient_id: number | null;
    body: string;
    /** Failure/skip reason for failed/skipped rows; null otherwise. */
    error: string | null;
    /** ISO 8601 UTC timestamp; formatted client-side via useDateTime(). */
    created_at: string;
    /** ISO 8601 UTC timestamp or null (only set once accepted by the provider). */
    sent_at: string | null;
};

/** Server-side list JSON:API state echoed back by the SMS-log index controller. */
export type SmsLogQuery = TableState<{
    status: string;
    type: string;
    start_date: string;
    end_date: string;
}>;

export type SmsLogIndexProps = {
    smsLogs: Paginated<SmsLogItem>;
    query: SmsLogQuery;
};
