# Identity & clinic membership

Three distinct layers — keep them separate:

- `users` — global auth identity (login): email (required, unique), phone (nullable, unique), first_name/last_name, locale, timezone. NO `clinic_id`/`tenant_id` — one user relates to several clinics over time (multi-clinic staff, B2C patient).
- `doctors` — doctor PROFILE (calendar/medical identity): `user_id` UNIQUE (MVP: one doctor profile per user), `clinic_id`, specialization, bio, license. Calendar visibility comes from this row, NOT the `doctor` role. (Faz 3 multi-clinic: → `clinic_doctor` pivot, UNIQUE relaxes.)
- `patients` — clinic-owned RECORD: `clinic_id` NOT NULL, `user_id` nullable (always null in MVP — no patient login until Faz 2). `phone` unique per clinic. The same person across clinics = multiple `patients` rows.

Staff↔clinic membership is the Spatie Teams role assignment (`clinic_id` on `model_has_roles`), NOT a `users` column; `doctors`/`patients` profiles are an additional layer, not the membership link. One identity wears multiple hats (doctor at A, patient at B) — isolation comes from `ClinicScope` + clinic-scoped roles (a role at clinic A grants nothing at B).

## Patient identity number

- `patients` carries `identity_type` (`tckn` | `foreign_id` | `passport`), `identity_number`, `identity_number_hash` and `identity_country_id`. All nullable — a walk-in or legacy patient must still be creatable; e-Nabız later queues an identity-less record as unsendable instead of blocking the record.
- Validate per type: TCKN = 11 digits, first digit non-zero, checksum rule (length alone is not validation); YKN = 11 digits starting with 99; passport = alphanumeric with `identity_country_id` required.
- `identity_number` is encrypted; lookup and uniqueness go through `identity_number_hash` (HMAC). Identity search is always exact match, so never add a `LIKE` search over it. Uniqueness is `UNIQUE(clinic_id, identity_type, identity_country_id, identity_number_hash)` partial on non-null — the country is part of the key because passport numbers collide across countries.
- The identity number is identity data, NOT a special category of personal data — it does not carry the anamnesis/treatment encryption regime's obligations, but every read of it belongs in the access log.

