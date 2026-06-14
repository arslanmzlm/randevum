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

export type DashboardProps = {
    /** Empty when the user lacks followUps.view (widget hidden client-side). */
    followUps: FollowUpReminder[];
};
