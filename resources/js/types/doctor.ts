/** Canonical doctor shape emitted by DoctorResource (index + edit + mine). */
export type Doctor = {
    id: number;
    user_id: number;
    /** Computed full name (first_name + last_name). */
    name: string;
    first_name: string;
    last_name: string;
    email: string;
    /** trim("{title} {name}") — shown wherever a doctor name appears. */
    display_name: string;
    title: string | null;
    specialization: string | null;
    bio: string | null;
    license_number: string | null;
    certificate: string | null;
    is_active: boolean;
    avatar_url: string | null;
    is_self: boolean;
    /** ISO-8601 UTC instant the doctor was offboarded; null when still employed. */
    left_at: string | null;
    /** left_at !== null — drives the "Ayrılanlar" view and offboard gating. */
    is_offboarded: boolean;
};

export type DoctorIndexProps = {
    doctors: Doctor[];
    hasOwnProfile: boolean;
    /** Permission + instance state (no own profile yet) — stays a page prop. */
    canCreateOwn: boolean;
};

export type DoctorEditProps = {
    doctor: Doctor;
    /** Ownership — cannot be a permission, stays a page prop. */
    canEditSelf: boolean;
};
