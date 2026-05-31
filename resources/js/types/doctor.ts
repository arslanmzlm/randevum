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
};

export type DoctorIndexProps = {
    doctors: Doctor[];
    canManage: boolean;
    hasOwnProfile: boolean;
    canCreateOwn: boolean;
};

export type DoctorEditProps = {
    doctor: Doctor;
    canManage: boolean;
    canEditSelf: boolean;
};
