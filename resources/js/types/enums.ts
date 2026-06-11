/**
 * String-backed backend enums mirrored as TS unions — ONLY the ones the frontend
 * actually branches on (status colors/labels, availability messages). Values must
 * match the corresponding `app/Enums/*.php` exactly. Don't mirror enums the FE
 * never reasons about (SmsStatus, SmsType, LegalDocumentType, …) — no payoff.
 */

/** Mirrors `App\Enums\AppointmentStatus`. */
export type AppointmentStatus =
    | 'pending'
    | 'confirmed'
    | 'rescheduled'
    | 'arrived'
    | 'completed'
    | 'cancelled'
    | 'no_show';

/** Mirrors `App\Enums\AvailabilityReason`; values also match the `appointment.errors.*` lang keys. */
export type AvailabilityReason = 'outside_hours' | 'exception' | 'conflict';

/** Mirrors `App\Enums\TreatmentStatus` (Voided unused until 1.28; the FE still labels it). */
export type TreatmentStatus = 'draft' | 'completed' | 'voided';

/** Mirrors `App\Enums\PaymentMethod`; values key the `payment.method.*` lang labels. */
export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'cheque';
