import type { InertiaForm } from '@inertiajs/vue3';
import type {
    AnamnesisDiabetes,
    AnamnesisFieldType,
    AnamnesisPregnancy,
    PatientGender,
} from '@/types/enums';

/** A stored answer in the `extra` bag, keyed by its definition's `key`. */
export type AnamnesisExtraValue = string | number | boolean | string[] | null;

/**
 * Form-side `extra` value. A `date` definition binds a Date (the PrimeVue DatePicker's model);
 * the section's `form.transform` serializes it back to 'YYYY-MM-DD' before submit.
 */
export type AnamnesisExtraFormValue = AnamnesisExtraValue | Date;

/**
 * A dynamic field definition (vertical-seeded) that drives one control in the form, its
 * validation rule and its PDF row. `label`, `group` and the option labels arrive already
 * translated from the server — the frontend never translates a definition.
 */
export type AnamnesisFieldDefinition = {
    key: string;
    label: string;
    /** The group heading this field renders under. */
    group: string;
    type: AnamnesisFieldType;
    /** Non-null only for `select` / `multiselect`. */
    options: { value: string; label: string }[] | null;
    required: boolean;
    sort: number;
};

/**
 * Flat anamnesis shape emitted by AnamnesisResource; the whole object is `null` until the record
 * is first saved. `blood_type`/`smoking`/`alcohol` stay loosely typed — the FE does not branch on
 * them. `diabetes`/`pregnancy` DO get branched on (AnamnesisSection's risk summary), so they mirror
 * their backend constants as unions (frontend-components rule).
 */
export type Anamnesis = {
    blood_type: string | null;
    height_cm: number | null;
    weight_kg: number | null;
    /** Derived from height/weight server-side; never stored and never submitted. */
    bmi: number | null;
    smoking: string | null;
    alcohol: string | null;
    diabetes: AnamnesisDiabetes | null;
    pregnancy: AnamnesisPregnancy | null;
    hypertension: boolean;
    cardiovascular: boolean;
    respiratory: boolean;
    kidney_liver: boolean;
    thyroid: boolean;
    epilepsy: boolean;
    blood_thinners: boolean;
    bleeding_disorder: boolean;
    infectious_disease: boolean;
    infectious_disease_note: string | null;
    regular_medications: string | null;
    other_chronic: string | null;
    allergies: string | null;
    surgery_history: string | null;
    family_history: string | null;
    menstrual_notes: string | null;
    /** Vertical-specific answers, keyed by `AnamnesisFieldDefinition.key`. */
    extra: Record<string, AnamnesisExtraValue>;
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
    diabetes: AnamnesisDiabetes | null;
    pregnancy: AnamnesisPregnancy | null;
    hypertension: boolean;
    cardiovascular: boolean;
    respiratory: boolean;
    kidney_liver: boolean;
    thyroid: boolean;
    epilepsy: boolean;
    blood_thinners: boolean;
    bleeding_disorder: boolean;
    infectious_disease: boolean;
    infectious_disease_note: string;
    regular_medications: string;
    other_chronic: string;
    allergies: string;
    surgery_history: string;
    family_history: string;
    menstrual_notes: string;
    extra: Record<string, AnamnesisExtraFormValue>;
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
