# Anamnesis & dynamic fields

Structured anamnesis (health intake) and other custom forms use an EAV pattern (`fields` + `field_values`). This is distinct from the per-treatment clinical narrative (complaint / diagnosis / treatment_process), which lives in the vertical detail table.

## Structure

- `fields` defines a form field: `vertical_id` (vertical-default fields), `clinic_id` nullable (clinic override), `entity_type` (`patient` | `treatment`), `key` (code-friendly, e.g. `blood_type`), `label_key` + `group_key` (i18n lang keys), `type`, `options` (json), `is_required`, `order`, `is_active`.
- `field_values` stores answers: `field_id`, polymorphic `entity_type`/`entity_id`, `value` (always text; the app casts by `fields.type`).
- Field titles/labels ALWAYS come from lang keys (e.g. `__('health.clinic_form_title')`, `__('health.fields.blood_type')`) — never hardcode strings like "anamnez"/"Sağlık Bilgilerim". The same form renders different titles per UI (clinic vs patient) via different lang keys.

## Form ownership (layered, not either/or)

- Base = vertical-default fields, superadmin-managed (`fields.vertical_id`). Every clinic in a vertical gets a working form with zero setup.
- Override = clinic-specific fields (`fields.clinic_id`, Faz 3) layered on top — a clinic adds/hides/reorders fields. Never require a clinic to build its form from scratch before it can use one.

## Field scope (reuse within a clinic)

- Stable, patient-intrinsic facts (blood type, allergies, chronic conditions) → `entity_type='patient'`: stored once on the patient's clinic record, reviewed/updated each visit, not re-entered.
- Visit-specific data (today's complaint, current meds) → `entity_type='treatment'`: captured per visit.

## Cross-clinic = patient-owned pre-fill ONLY (Faz 2)

- A clinic NEVER reads another clinic's anamnesis. Each clinic's `field_values` are its own authoritative, clinic-owned record; there is no clinic-to-clinic data flow.
- A patient-owned store of stable health facts MAY pre-fill a clinic's anamnesis form (patient action). The patient edits/removes before submitting; on submit, answers are written to THAT clinic's `field_values` (clinic-owned). This is patient-controlled convenience, not record sharing.
- Pre-fill needs a patient app account → Faz 2 (B2C). The store's implementation (dedicated `patient_profiles` vs user-scoped EAV) is decided at Faz 2. This adds no medical/legal risk beyond patient-self-filled anamnesis, which Faz 2 already includes.

## Printable PDF

- Anamnesis prints to PDF (`resources/views/pdf/anamnesis.blade.php`): structure looped from `fields`, answers joined from `field_values`. The clinic logo/header comes from clinic settings (Medialibrary `logo`) — branding is a PDF-template concern, independent of who defined the fields.

## Timing

- MVP: `fields`/`field_values` exist but are empty; anamnesis is the free-text patient note. Schema is ready so Faz 2 opens with no migration.
- Faz 2: seed per-vertical default fields (podiatry ~10–15), structured form + PDF, then patient-owned pre-fill.
