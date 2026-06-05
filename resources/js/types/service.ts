import type { Paginated, TableState } from '@/types/table';

/** Canonical service shape emitted by ServiceResource (index + edit). */
export type Service = {
    id: number;
    name: string;
    description: string | null;
    /** decimal:2 serialized as a string, e.g. "1500.00". */
    price: string;
    duration_minutes: number | null;
    default_complaint: string | null;
    default_diagnosis: string | null;
    default_treatment_process: string | null;
    is_active: boolean;
};

/** Server-side list JSON:API state echoed back by the controller. */
export type ServiceQuery = TableState<{
    is_active: boolean | null;
}>;

export type ServiceIndexProps = {
    services: Paginated<Service>;
    query: ServiceQuery;
    canManage: boolean;
    /** ISO 4217 code of the active clinic, for price formatting. */
    currency: string;
};

/** Editable fields shared by the create and edit forms. */
export type ServiceFormData = {
    name: string;
    description: string;
    /** null on a fresh create form so the currency input renders empty, not ₺0,00. */
    price: number | null;
    /** Drives the appointment slot length; null falls back to the clinic default. */
    duration_minutes: number | null;
    default_complaint: string;
    default_diagnosis: string;
    default_treatment_process: string;
    is_active: boolean;
};

export type ServiceCreateProps = {
    currency: string;
};

export type ServiceEditProps = {
    service: Service;
    currency: string;
};
