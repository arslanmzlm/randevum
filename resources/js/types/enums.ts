/**
 * String-backed backend enums mirrored as TS unions — ONLY the ones the frontend
 * actually branches on (status colors/labels, availability messages, settings keys).
 * Values must match the corresponding `app/Enums/*.php` exactly. Don't mirror enums
 * the FE never reasons about (SmsStatus, LegalDocumentType, …) — no payoff.
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

/** Mirrors `App\Enums\CaseStatus`; the FE branches on it (status→severity/label) and gates transitions. */
export type CaseStatus = 'open' | 'suspended' | 'follow_up' | 'closed';

/** Mirrors `App\Enums\PaymentMethod`; values key the `payment.method.*` lang labels. */
export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'cheque';

/** Mirrors `App\Enums\TransactionStatus`; the FE branches on it for refund styling (1.28). */
export type TransactionStatus =
    | 'pending'
    | 'completed'
    | 'partially_refunded'
    | 'refunded';

/**
 * Mirrors `App\Enums\SmsType`. The SMS-settings page keys its toggle map off the
 * clinic-scoped types (every case except `otp`, which always bypasses the gate).
 */
export type SmsType =
    | 'appointment_created'
    | 'appointment_cancelled'
    | 'appointment_rescheduled'
    | 'reminder_24h'
    | 'reminder_1h'
    | 'balance_reminder'
    | 'otp';
