import type { InertiaForm } from '@inertiajs/vue3';
import type { WorkingHours } from '@/types/appointment';
import type { PaymentMethod, TreatmentStatus } from '@/types/enums';

/** A selectable service in the line editors (catalog price prefills the line on selection). */
export type TreatmentServiceOption = {
    id: number;
    name: string;
    price: string;
    default_complaint: string | null;
    default_diagnosis: string | null;
    default_treatment_process: string | null;
};

/** A selectable product in the line editor — shows live stock so staff see a negative-going deduct. */
export type TreatmentProductOption = {
    id: number;
    name: string;
    price: string;
    unit: string;
    current_stock: number;
};

/** One of the patient+doctor's Open cases, for the "pick existing case" dropdown. */
export type OpenCaseOption = {
    id: number;
    title: string;
    /** ISO 8601 — used to derive the "vaka yaşı" (case age) label. */
    opened_at: string;
    treatments_count: number;
};

/** The Draft treatment being filled (TreatmentProcessResource). */
export type ProcessTreatment = {
    id: number;
    status: 'draft';
    appointment: {
        id: number;
        starts_at: string;
        is_walk_in: boolean;
        /** Visit-intent service picked at booking — preselects the first service line. */
        service_id: number | null;
        appointment_type: { name: string; color: string } | null;
    };
    patient: {
        id: number;
        full_name: string;
        phone: string | null;
        notes: string | null;
    };
    doctor: { id: number; display_name: string };
    details: {
        complaint: string | null;
        diagnosis: string | null;
        treatment_process: string | null;
    };
    notes: string | null;
};

/** Props for the `treatments/Process` page (TreatmentController@process). */
export type TreatmentProcessProps = {
    treatment: ProcessTreatment;
    services: TreatmentServiceOption[];
    products: TreatmentProductOption[];
    openCases: OpenCaseOption[];
    defaultSlotDuration: number;
    workingHours: WorkingHours;
};

/** A service line in the Process form (`unit_price` prefilled from catalog, freely editable). */
export type ServiceLineForm = {
    service_id: number | null;
    quantity: number;
    unit_price: number | null;
    discount_amount: number | null;
};

/** A product line in the Process form. */
export type ProductLineForm = {
    product_id: number | null;
    quantity: number;
    unit_price: number | null;
    discount_amount: number | null;
};

export type CaseMode = 'none' | 'existing' | 'new';
/** Payment intent at completion: received = one or more method rows, none = all debt. */
export type PaymentEntryMode = 'received' | 'none';

/** One payment row — a treatment's payment may be split across methods (part card, part cash). */
export type PaymentRowForm = {
    method: PaymentMethod | null;
    amount: number | null;
};
export type FollowUpMode = 'none' | 'single' | 'package';
export type FollowUpInterval = 'weekly' | 'biweekly' | 'monthly';

/** The complete Process form payload (the page owns useForm; partials inject it). */
export type TreatmentFormData = {
    details: {
        complaint: string;
        diagnosis: string;
        treatment_process: string;
    };
    notes: string;
    discount_amount: number | null;
    services: ServiceLineForm[];
    products: ProductLineForm[];
    case_mode: CaseMode;
    case_id: number | null;
    new_case_title: string;
    payment: {
        mode: PaymentEntryMode;
        rows: PaymentRowForm[];
    };
    follow_up: {
        mode: FollowUpMode;
        date: Date | null;
        time: string;
        count: number;
        interval: FollowUpInterval;
        service_id: number | null;
    };
};

export type TreatmentForm = InertiaForm<TreatmentFormData>;

/** A persisted line row on the Show page (snapshot-safe service/product name). */
export type TreatmentLine = {
    id: number;
    name: string;
    quantity: number;
    unit_price: string;
    discount_amount: string;
    subtotal: string;
    note: string | null;
};

/** Props for the `treatments/Show` page (TreatmentController@show). */
export type TreatmentShowProps = {
    treatment: {
        id: number;
        status: TreatmentStatus;
        completed_at: string | null;
        appointment: { id: number; starts_at: string };
        patient: { id: number; full_name: string };
        doctor: { display_name: string };
        case: { id: number; title: string } | null;
        details: {
            complaint: string | null;
            diagnosis: string | null;
            treatment_process: string | null;
        };
        serviceLines: TreatmentLine[];
        productLines: TreatmentLine[];
        notes: string | null;
        subtotal_amount: string;
        discount_amount: string;
        total_amount: string;
        paid_total: string;
    };
};

/** One row of the patient "Tedavi Geçmişi" list (PatientController@show). */
export type PatientTreatmentHistoryItem = {
    id: number;
    completed_at: string | null;
    status: TreatmentStatus;
    title: string | null;
    total_amount: string;
    doctor_name: string;
    case_title: string | null;
};
