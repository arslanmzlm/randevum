# Anamnesis & dynamic fields

- Anamnesis & custom forms use an EAV pattern (`fields` + `field_values`), distinct from the per-treatment clinical narrative (complaint / diagnosis / treatment_process) in the vertical detail table.
- `fields.entity_type` (`patient` | `treatment`) scopes a field: stable patient-intrinsic facts (blood type, allergies, chronic conditions) = `patient` (stored once on the clinic record, reviewed each visit); visit-specific data = `treatment` (captured per visit).
- Field titles/labels ALWAYS come from lang keys — never hardcode "anamnez"/"Sağlık Bilgilerim"; the same form renders different titles per UI (clinic vs patient) via different keys.
- Form ownership is layered: vertical-default fields (superadmin-managed) give every clinic a working form with zero setup; clinic overrides (`fields.clinic_id`, Faz 3) add/hide/reorder on top — never make a clinic build from scratch.
- A clinic NEVER reads another clinic's anamnesis; each clinic's `field_values` are clinic-owned. Cross-clinic = patient-owned pre-fill ONLY (Faz 2): a patient-owned store MAY pre-fill THIS clinic's form (patient action), answers write to this clinic's `field_values` — convenience, not record sharing.
- MVP: `fields`/`field_values` exist but empty; anamnesis is the free-text patient note (schema ready, no Faz 2 migration). Faz 2: seed per-vertical defaults (podiatry ~10–15), structured form + printable PDF, then pre-fill.
- Full column schema, layered-ownership detail, pre-fill flow, PDF template: see `.localdev/docs/data-model.md`.
- `anamneses.extra` is stored **keys plain, values encrypted** — the column stays `jsonb`; each value is encrypted individually, never the whole blob (`encrypted:array` would force the column to `text` and kill `extra ? 'key'` + GIN indexing). No per-field `is_sensitive` flag: the rule is uniform.
- Because keys stay readable, **key presence must never carry information**: a defined field is either always written or never written. Do not define a key that only exists when a condition holds (`hiv_status`), and do not read "answered" as an answer.
- Value filtering on `extra` is gone by design. When a field genuinely needs SQL filtering, add a searchable projection next to it (typed column, or a small `key → value` projection table for runtime-defined fields) — never decrypt the blob back to plaintext to get a `WHERE` working.

