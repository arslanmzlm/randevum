import type { InertiaForm } from '@inertiajs/vue3';
import type { Anamnesis } from '@/types/anamnesis';
import type { AppointmentTypeOption, WorkingHours } from '@/types/appointment';
import type { TransactionItem } from '@/types/balance';
import type { PaymentMethod, TreatmentStatus } from '@/types/enums';
import type { MediaItem } from '@/types/media';
import type { PatientGender } from '@/types/patient';
import type { InstallmentPlanForm } from '@/types/payment-plan';

/** Active appointment type for the follow-up booking (duration resolves server-side). */
export type FollowUpAppointmentTypeOption = Pick<
    AppointmentTypeOption,
    'id' | 'name' | 'color'
>;

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
        /** Drives the women-only pregnancy field in the anamnesis section. */
        gender: PatientGender | null;
    };
    doctor: { id: number; display_name: string };
    details: {
        complaint: string | null;
        diagnosis: string | null;
        treatment_process: string | null;
    };
    notes: string | null;
    /** Files attached to this Draft treatment. Absent when the user lacks
     *  `treatments.media.view` (server omits it). */
    media?: MediaItem[];
};

/** Props for the `treatments/Process` page (TreatmentController@process). */
export type TreatmentProcessProps = {
    treatment: ProcessTreatment;
    services: TreatmentServiceOption[];
    products: TreatmentProductOption[];
    openCases: OpenCaseOption[];
    appointmentTypes: FollowUpAppointmentTypeOption[];
    defaultSlotDuration: number;
    workingHours: WorkingHours;
    /** The patient's structured health-intake record; null until first saved. */
    anamnesis: Anamnesis | null;
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
/**
 * Payment intent at completion: received = one or more method rows, none = all debt,
 * installment = a scheduled taksit plan (mutually exclusive with the method rows).
 */
export type PaymentEntryMode = 'received' | 'none' | 'installment';

/** One payment row — a treatment's payment may be split across methods (part card, part cash). */
export type PaymentRowForm = {
    method: PaymentMethod | null;
    amount: number | null;
};
export type FollowUpMode = 'none' | 'single' | 'package';
/** 'custom' spaces occurrences by a free day count (the generator's `interval_days`). */
export type FollowUpInterval = 'weekly' | 'biweekly' | 'monthly' | 'custom';

/** One generated/editable occurrence row in package mode (clinic-local date + "HH:mm" time).
 *  Each row carries its own type and duration so a package can mix e.g. kontrol + muayene. */
export type FollowUpOccurrenceForm = {
    date: Date | null;
    time: string;
    duration_minutes: number | null;
    appointment_type_id: number | null;
};

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
        /** Installment-mode sub-state (the dynamic builder); ignored unless mode = 'installment'. */
        installment: InstallmentPlanForm;
    };
    follow_up: {
        mode: FollowUpMode;
        date: Date | null;
        time: string;
        /** Generator params (client-only) — drive the package occurrence list, not sent as-is. */
        count: number;
        interval: FollowUpInterval;
        /** Editable, generated occurrence rows (package mode); empty for single/none. */
        occurrences: FollowUpOccurrenceForm[];
        service_id: number | null;
        /** Single mode's values; in package mode they only seed the generated rows (client-only). */
        appointment_type_id: number | null;
        duration_minutes: number | null;
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
        /** This treatment's transactions, newest first. Absent when the user lacks
         *  `transactions.viewAny` (server omits it). */
        transactions?: TransactionItem[];
        /** Attached files. Absent when the user lacks `treatments.media.view`. */
        media?: MediaItem[];
    };
};

/** A treatment a standalone payment may optionally attach to (both Draft & Completed). */
export type PaymentTreatmentOption = {
    id: number;
    title: string | null;
    status: TreatmentStatus;
    total_amount: string;
};

/** Payload posted to `payments.store` by RecordPaymentDialog. `paid_at` is a Date for the
 *  DatePicker, serialized to a Y-m-d string via `form.transform` (empty ⇒ server uses now()). */
export type RecordPaymentFormData = {
    patient_id: number;
    treatment_id: number | null;
    amount: number | null;
    payment_method: PaymentMethod | null;
    note: string;
    paid_at: Date | null;
};

/** One row of the patient "Tedavi Geçmişi" list (PatientController@show). */
export type PatientTreatmentHistoryItem = {
    id: number;
    completed_at: string | null;
    status: TreatmentStatus;
    title: string | null;
    total_amount: string;
    doctor_name: string;
    /** Owning doctor — lets the FE identify which ungrouped treatments a user can group. */
    doctor_id: number;
    /** null ⇒ ungrouped (eligible for retrospective case linking). */
    case_id: number | null;
    case_title: string | null;
};
