import type { InertiaForm } from '@inertiajs/vue3';
import type { CustomizableSmsType, SmsType } from '@/types/enums';

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
    auto_no_show_enabled: boolean;
    auto_no_show_grace_hours: number;
    working_hours: WorkingHours;
    logo_url: string | null;
    cover_url: string | null;
    cover_mobile_url: string | null;
};

/** Editable clinic-settings fields (media is handled separately via ImageUploadField). */
export type ClinicFormData = {
    name: string;
    slug: string;
    description: string;
    phone: string;
    email: string;
    website: string;
    country_id: number;
    city_id: number | null;
    district: string;
    address: string;
    postal_code: string;
    default_slot_duration_minutes: number;
    auto_no_show_enabled: boolean;
    auto_no_show_grace_hours: number;
    working_hours: WorkingHours;
};

/** The clinic-settings Inertia form, shared with the field partial components. */
export type ClinicForm = InertiaForm<ClinicFormData>;

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
    /** Active clinic's vertical — gates vertical-specific UI (e.g. podiatry anamnesis). */
    vertical: { slug: string | null };
};

/** The SMS preferences tab payload on the clinic profile page (null when not permitted). */
export type ClinicSmsPanel = {
    settings: Record<SmsType, boolean>;
    templates: Record<CustomizableSmsType, string | null>;
    defaults: Record<CustomizableSmsType, string>;
    variables: string[];
    sample: Record<string, string>;
    quota: {
        used: number;
        allowance: number;
        remaining: number;
        resets_at: string;
    };
};
