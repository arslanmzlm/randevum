# Deletion, edit windows & retention

- Drive ALL edit/delete time windows from `config/platform.php` (env-overridable); read them in the Service layer on every edit/delete. Never inline the literal durations.
  - `edit_windows.treatment` = 48h, `edit_windows.case` = 48h, `edit_windows.transaction_delete` = 1h
  - `appointment.hard_delete_allowed_states` = `['Confirmed']`
  - `reminders.offsets` = [24h, 1h], `reminders.window_minutes` = 5
- No hard delete by default. Master/identity data uses soft delete; operational records change state instead.
- Treatments & cases: never hard delete. Within the 48h window an edit is allowed; after it, a treatment edit becomes a new "correction" record and a case allows only a status change (close/reopen).
- Transactions are immutable: hard delete only within 1h; afterwards only a reversing counter-entry (negative amount). Reversals/refunds are never hard-deleted (audit trail).
- Appointments: hard delete only when the status is `Confirmed` and the start time has not passed (no time limit); otherwise transition to `Cancelled`.
- Soft-delete (admin panel) applies to `users`, `patients`, `doctors`, `clinics`, `services`, `products`. Do NOT soft-delete `appointments`/`treatments`/`transactions` (use state). `product_stocks` has no soft delete.
- Notes (patient/case) may always be edited/deleted, but every change must be audit-logged.

## Medical media retention (KVKK)

- Media hard delete (DB row + S3 file) only within the 48h window; afterwards soft delete (`deleted_at`) and keep the file in storage for the retention period (hidden from UI only).
- Always retain originals (Spatie originals + conversions) as a medical-legal record. Adult health records carry a 20-year retention requirement.
- Originals auto-move to S3 Glacier (cold storage) after 6 months.
- Never auto-trigger KVKK erasure. Route: patient request → superadmin legal review → manual anonymization (clear `patients` PII, keep medical rows linked). Never hard-delete the medical record.

## Legal documents & consent

- Version legal documents immutably: editing text creates a NEW row (new `version`, prior `is_active=false`); never delete old rows. Existing `consents` stay bound to the old `legal_document_id`.
- Capture consent audit trail on `consents`: `accepted_at`, `ip_address`, `user_agent`, `accepted_by_user_id` (null when the user self-consents). Share one form+consent infrastructure between KVKK consent and the anamnesis form.
