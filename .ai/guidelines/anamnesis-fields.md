# Anamnesis & dynamic fields

Structured anamnesis and other custom forms use an EAV pattern (`fields` + `field_values`), distinct from the per-treatment clinical narrative (complaint / diagnosis / treatment_process) in the vertical detail table.

## Structure

- `fields` defines a form field: `vertical_id` (vertical-default), `clinic_id` nullable (clinic override), `entity_type` (`patient` | `treatment`), `key` (code-friendly, e.g. `blood_type`), `label_key` + `group_key` (i18n keys), `type`, `options` (json), `is_required`, `order`, `is_active`.
- `field_values` stores answers: `field_id`, polymorphic `entity_type`/`entity_id`, `value` (always text; cast by `fields.type`).
- Field titles/labels ALWAYS come from lang keys (e.g. `__('health.fields.blood_type')`) — never hardcode "anamnez"/"Sağlık Bilgilerim". The same form renders different titles per UI (clinic vs patient) via different lang keys.

## Form ownership (layered)

- Base = vertical-default fields, superadmin-managed (`fields.vertical_id`); every clinic gets a working form with zero setup.
- Override = clinic-specific fields (`fields.clinic_id`, Faz 3) layered on top (add/hide/reorder). Never require a clinic to build a form from scratch.

## Field scope (reuse within a clinic)

- Stable patient-intrinsic facts (blood type, allergies, chronic conditions) → `entity_type='patient'`: stored once on the patient's clinic record, reviewed each visit, not re-entered.
- Visit-specific data (today's complaint, current meds) → `entity_type='treatment'`: captured per visit.

## Cross-clinic = patient-owned pre-fill ONLY (Faz 2)

- A clinic NEVER reads another clinic's anamnesis. Each clinic's `field_values` are its own clinic-owned record; no clinic-to-clinic flow.
- A patient-owned store of stable health facts MAY pre-fill a clinic's form (patient action): patient edits/removes before submit; on submit, answers write to THAT clinic's `field_values`. Patient-controlled convenience, not record sharing.
- Pre-fill needs a patient app account → Faz 2 (B2C). Store implementation (`patient_profiles` vs user-scoped EAV) is decided at Faz 2; no added medical/legal risk over patient-self-filled anamnesis.

## Printable PDF

- Anamnesis prints to PDF (`resources/views/pdf/anamnesis.blade.php`): structure looped from `fields`, answers joined from `field_values`. Clinic logo/header from clinic settings (Medialibrary `logo`) — branding is a PDF-template concern.

## Timing

- MVP: `fields`/`field_values` exist but are empty; anamnesis is the free-text patient note. Schema ready so Faz 2 needs no migration.
- Faz 2: seed per-vertical default fields (podiatry ~10–15), structured form + PDF, then patient-owned pre-fill.
