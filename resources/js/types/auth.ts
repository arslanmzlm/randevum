export type User = {
    id: number;
    first_name: string;
    last_name: string;
    /** Computed full name (first_name + last_name). */
    name: string;
    email: string;
    phone: string | null;
    avatar?: string;
    email_verified_at: string | null;
    phone_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    /** Null when the request is unauthenticated (e.g. login page). */
    user: User | null;
};
