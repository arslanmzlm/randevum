import type { InertiaForm } from '@inertiajs/vue3';
import type { PatientGender } from '@/types/patient';

/**
 * Flat anamnesis field shape emitted by AnamnesisResource; the whole object is `null` until the
 * record is first saved. The select strings (blood_type/smoking/alcohol/diabetes/pregnancy) are
 * typed loosely — the FE does not branch on them, so no enum unions (frontend-components rule).
 */
export type Anamnesis = {
    blood_type: string | null;
    height_cm: number | null;
    weight_kg: number | null;
    smoking: string | null;
    alcohol: string | null;
    diabetes: string | null;
    hypertension: boolean;
    cardiovascular: boolean;
    blood_thinners: boolean;
    regular_medications: string | null;
    other_chronic: string | null;
    allergies: string | null;
    pregnancy: string | null;
    foot_surgery_history: string | null;
    diabetic_foot_history: boolean;
    current_foot_complaint: string | null;
    /** ISO 8601, or null when never filled. */
    updated_at: string | null;
};

/** The editable anamnesis payload — text fields become '' (not null) so the Textareas bind cleanly. */
export type AnamnesisFormData = {
    blood_type: string | null;
    height_cm: number | null;
    weight_kg: number | null;
    smoking: string | null;
    alcohol: string | null;
    diabetes: string | null;
    hypertension: boolean;
    cardiovascular: boolean;
    blood_thinners: boolean;
    regular_medications: string;
    other_chronic: string;
    allergies: string;
    pregnancy: string | null;
    foot_surgery_history: string;
    diabetic_foot_history: boolean;
    current_foot_complaint: string;
};

export type AnamnesisForm = InertiaForm<AnamnesisFormData>;

/**
 * The minimal patient shape the section needs — id (for the routes) and gender (drives the
 * women-only pregnancy field). Both the full `Patient` and the slim `treatment.patient` satisfy it.
 */
export type AnamnesisPatient = {
    id: number;
    gender: PatientGender | null;
};
