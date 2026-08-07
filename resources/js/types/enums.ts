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

/** Mirrors `App\Enums\FollowUpStatus`; the FE branches on it (status→severity/label). */
export type FollowUpStatus = 'open' | 'done' | 'cancelled';

/** Mirrors `App\Enums\PaymentMethod`; values key the `payment.method.*` lang labels. */
export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'cheque';

/** Mirrors `App\Enums\TransactionStatus`; the FE branches on it for refund styling (1.28). */
export type TransactionStatus =
    | 'pending'
    | 'completed'
    | 'partially_refunded'
    | 'refunded';

/** Mirrors `App\Enums\PaymentPlanStatus`; the FE branches on it (status→severity/label). */
export type PaymentPlanStatus = 'active' | 'completed' | 'cancelled';

/**
 * Mirrors `App\Enums\InstallmentStatus`; the FE branches on it (status→severity/label).
 * "Overdue" is NOT a stored status — it is derived (`is_overdue`) and rendered as a distinct
 * visual on top of `pending`.
 */
export type InstallmentStatus =
    | 'pending'
    | 'partially_paid'
    | 'paid'
    | 'cancelled';

/**
 * Mirrors `App\Enums\SmsType`. The SMS-settings page keys its toggle map off the
 * clinic-scoped types (every case except `otp`, which always bypasses the gate);
 * the SMS-log surfaces map every case to a `sms.type.*` label.
 */
export type SmsType =
    | 'appointment_created'
    | 'appointment_cancelled'
    | 'appointment_rescheduled'
    | 'reminder_24h'
    | 'reminder_1h'
    | 'balance_reminder'
    | 'installment_due_7d'
    | 'installment_due_1d'
    | 'otp';

/**
 * The SMS types the settings-page template editor operates on — the appointment set.
 * Server-side `SmsType::customizableCases()` also marks the installment-due types
 * customizable, but the UI surfaces those as plain on/off toggles (their body carries
 * an `:amount` variable the template editor's allow-list doesn't expose), so they stay
 * out of this union. Keys the `templates`/`defaults` maps on the SMS-settings page.
 */
export type CustomizableSmsType =
    | 'appointment_created'
    | 'appointment_cancelled'
    | 'appointment_rescheduled'
    | 'reminder_24h'
    | 'reminder_1h';

/** Mirrors `App\Enums\SmsStatus`; the SMS-log surfaces branch on it (status→severity/label). */
export type SmsStatus = 'queued' | 'sent' | 'failed' | 'skipped';

/**
 * Mirrors `App\Enums\StockMovementReason`; the stock-history list branches on it
 * (reason→severity/label) and the manual stock dialog offers a subset of the cases.
 */
export type StockMovementReason =
    | 'treatment_usage'
    | 'manual_adjustment'
    | 'return'
    | 'initial';

/**
 * Mirrors `App\Enums\ReportTab`; the report page branches on it (which panel renders and
 * which column set the breakdown table gets).
 */
export type ReportTab =
    | 'finance'
    | 'doctor'
    | 'service'
    | 'product'
    | 'appointment_type'
    | 'expense_owner';
