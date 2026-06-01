/**
 * Laravel paginated resource-collection shape (`Resource::collection($paginator)`):
 * data rows + nested `meta` (counts) + `links`. Reused by future server-side lists
 * and the planned 1.8b DataTable wrapper.
 */
export type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
};

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
};

/** Server-side list filter/sort/paginate state echoed back by the controller. */
export type PatientFilters = {
    search: string;
    sort_field: string;
    /** PrimeVue sort order serialized as a string: '1' asc, '-1' desc, '' none. */
    sort_order: string;
    gender: string;
    is_legacy: boolean | null;
    per_page: number;
};

export type PatientIndexProps = {
    patients: Paginated<Patient>;
    filters: PatientFilters;
    canManage: boolean;
    canDelete: boolean;
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

/** Shared restore prompt payload (flash) when a phone matches a soft-deleted patient. */
export type RestorablePatient = {
    id: number;
    full_name: string;
};

export type PatientShowProps = {
    patient: Patient;
    treatments: unknown[];
    canManage: boolean;
};

export type PatientEditProps = {
    patient: Patient;
};
