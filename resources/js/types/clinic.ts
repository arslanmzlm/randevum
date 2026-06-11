export const WEEK_DAYS = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
] as const;

export type WeekDay = (typeof WEEK_DAYS)[number];

/** A single day is either fully closed, or has open/close (+ optional lunch break). */
export type DayHours =
    | { closed: true }
    | { open: string; close: string; break: [string, string] | null };

export type WorkingHours = Record<WeekDay, DayHours>;

export type Clinic = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    country_id: number;
    city_id: number | null;
    district: string | null;
    address: string | null;
    postal_code: string | null;
    default_slot_duration_minutes: number;
    working_hours: WorkingHours;
    logo_url: string | null;
    cover_url: string | null;
    cover_mobile_url: string | null;
};

export type ClinicVertical = { id: number; name: string };
export type ClinicCountry = { id: number; name: string; code: string };
export type ClinicCity = { id: number; name: string };

/** Curated clinic identity shared on every page (shell logo + name + tz for date formatting). */
export type SharedClinic = {
    id: number;
    name: string;
    logo_url: string | null;
    /** Clinic IANA timezone — single source for useDateTime()/calendar local rendering. */
    timezone: string;
    /** ISO 4217 currency code — single source for useMoney() money formatting. */
    currency: string;
};
