# Deletion, edit windows & retention

- Drive ALL edit/delete windows from `config/platform.php` (env-overridable); read them in the Service layer on every edit/delete — never inline durations.
  - `edit_windows.treatment` = 48h, `edit_windows.case` = 48h, `edit_windows.transaction_delete` = 1h
  - `appointment.hard_delete_allowed_statuses` = `['confirmed']` (enum backing values, lowercase)
  - `reminders.offsets` = [24h, 1h], `reminders.window_minutes` = 5
- No hard delete by default. Master/identity data soft-deletes; operational records change state.
- Treatments & cases: never hard delete. Within 48h an edit is allowed; after, a treatment edit becomes a new "correction" record and a case allows only a status change (close/reopen).
- Transactions are immutable: hard delete only within 1h; afterwards only a reversing counter-entry (negative amount). Reversals/refunds are never hard-deleted (audit trail).
- Appointments: hard delete only when status is `Confirmed` and start time hasn't passed (no time limit); otherwise transition to `Cancelled`.
- Soft-delete (admin panel): `users`, `patients`, `doctors`, `clinics`, `services`, `products`. Do NOT soft-delete `appointments`/`treatments`/`transactions` (use state). `product_stocks` has no soft delete.
- Notes (patient/case) may always be edited/deleted, but every change is audit-logged.

## Medical media retention (KVKK)

- Media hard delete (DB row + S3 file) only within 48h; afterwards soft delete (`deleted_at`) and keep the file for the retention period (hidden from UI only).
- Always retain originals (Spatie originals + conversions) as a medical-legal record (adult health records: 20-year retention); originals auto-move to S3 Glacier after 6 months.
- Never auto-trigger KVKK erasure: patient request → superadmin review → manual anonymization (clear `patients` PII, keep medical rows). Never hard-delete the medical record.

## Legal documents & consent

- Version legal documents immutably: editing text creates a NEW row (new `version`, prior `is_active=false`); never delete old rows. Existing `consents` stay bound to the old `legal_document_id`.
- Capture consent audit trail on `consents`: `accepted_at`, `ip_address`, `user_agent`, `accepted_by_user_id` (null when self-consent). Share one form+consent infrastructure between KVKK consent and the anamnesis form.

## KVKK consent model (decided — full sourced memo in .localdev/docs/kvkk-consent-legal-research.md; a KVKK lawyer must review pre-launch)

- Roles (KVKK m.3): **clinic = controller (veri sorumlusu)**, **platform = processor (veri işleyen)**. Storing data is processing, NOT controllership.
- Patient KVKK is the **clinic's** responsibility — platform is only *disclosed* as a sub-processor in the clinic's aydınlatma metni. NEVER take a separate platform→patient consent (it signals controller status and raises liability).
- Treatment-purpose health-data processing needs **NO açık rıza** (m.6/3, secrecy-duty persons) — only aydınlatma (clinic, offline). So **MVP patient-create has no consent field**.
- Açık rıza must be the patient's OWN affirmative act (staff ticking a box ≠ valid) → patient açık rıza lives in the **Faz-2 patient self-service app**, not staff screens. `consents.consentable` morph + `revoked_at`/`revoked_reason` already support append-on-toggle.
- Platform liability shield = the **signup DPA (Veri İşleme Sözleşmesi) + ToS + Privacy** accepted by the clinic owner at registration (`consents.consentable_type='user'`). KVKK has no automatic joint-and-several liability; the DPA must contractually push misuse risk to the clinic.
- Legal-document content: MVP stores plain text (seeded from `resources/legal/<type>/<version>.md`), renders **escaped** (`{{ }}` + `whitespace-pre-line`, **no `v-html`**) → no XSS surface, no purifier. The Faz-3 DB-managed editor = **Tiptap** (outputs HTML) → switching the render to `v-html` REQUIRES server-side HTML sanitization (e.g. `mews/purifier`) first.
- Landmines: AWS Frankfurt = cross-border transfer (amended m.9, eff. 1 Jun 2024) → needs Kurum-notified SCCs; VERBİS registration falls on the clinics; if the platform ever uses patient/health data for its OWN purpose it becomes a controller for that purpose (keep a hard wall + irreversibly anonymise).
