import type { Paginated, TableState } from '@/types/table';

/** Canonical appointment-type shape emitted by AppointmentTypeResource (index + edit). */
export type AppointmentType = {
    id: number;
    name: string;
    /** Calendar color stored as '#RRGGBB'. */
    color: string;
    default_duration_minutes: number;
    is_active: boolean;
};

/** Server-side list state echoed back by the controller. */
export type AppointmentTypeQuery = TableState<{
    is_active: boolean | null;
}>;

export type AppointmentTypeIndexProps = {
    appointmentTypes: Paginated<AppointmentType>;
    query: AppointmentTypeQuery;
    /** Row behind a `?edit=<id>` link, resolved server-side so it opens even when off-page. */
    editing: AppointmentType | null;
};

/** Editable fields shared by the create and edit forms. */
export type AppointmentTypeFormData = {
    name: string;
    /** Always a '#RRGGBB' string; the ColorField manages the leading '#'. */
    color: string;
    /** Drives the booked slot length when no service is chosen; null falls back to the clinic default. */
    default_duration_minutes: number | null;
    is_active: boolean;
};
