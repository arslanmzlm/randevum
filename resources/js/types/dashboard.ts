import type { AppointmentStatus } from '@/types/enums';

/** One due follow-up surfaced in the dashboard "Bugün aranacaklar" widget. */
export type FollowUpReminder = {
    case_id: number;
    patient: { id: number; full_name: string; phone: string | null };
    doctor: { id: number; display_name: string };
    /** ISO date (Y-m-d), tz-less calendar date. */
    follow_up_date: string;
    follow_up_note: string | null;
    /** follow_up_date < today (clinic tz). */
    is_overdue: boolean;
};

/** One row of the takvim-özet today-schedule feed (dedicated, not the upcoming prop). */
export type TodayScheduleRow = {
    id: number;
    patient_id: number;
    patient_name: string;
    doctor_id: number;
    doctor_name: string;
    service_name: string | null;
    status: AppointmentStatus;
    is_walk_in: boolean;
    /** ISO 8601 UTC timestamp; formatted client-side via useDateTime(). */
    starts_at: string;
};

/** At-a-glance KPI counts for the active clinic; `null` ⇒ viewer lacks the gating permission. */
export type DashboardStats = {
    /** null ⇒ no `appointments.viewAny`; counts are doctor-scoped to the viewer. */
    appointments: {
        today: number;
        pending: number;
        this_week: number;
        /**
         * This-month no-show rate (doctor-scoped, current `status` column).
         * `percent` is one-decimal 0–100; the whole object is null when the
         * resolved-appointment denominator (`expected`) is 0.
         */
        no_show_rate: {
            percent: number;
            no_show: number;
            expected: number;
        } | null;
    } | null;
    /** null ⇒ no `transactions.viewAny`; `today_collected` is a 2-dp decimal string, always clinic-wide. */
    revenue: { today_collected: string; currency: string } | null;
    /**
     * The takvim-özet feed: the FULL clinic-local day for the visible doctor scope (no
     * now()-forward filter, no small-N cap — includes already-started/passed rows).
     * null ⇒ no `appointments.viewAny`.
     */
    today_schedule: TodayScheduleRow[] | null;
};

export type DashboardProps = {
    /** Empty when the user lacks followUps.view (widget hidden client-side). */
    followUps: FollowUpReminder[];
    stats: DashboardStats;
};
