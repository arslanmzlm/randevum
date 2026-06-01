# Identity & clinic membership

Three distinct layers — keep them separate:

- `users` — global auth identity (login): email (required, unique), phone (nullable, unique), first_name/last_name, locale, timezone. NO `clinic_id`/`tenant_id` — one user relates to several clinics over time (multi-clinic staff, B2C patient).
- `doctors` — doctor PROFILE (calendar/medical identity): `user_id` UNIQUE (MVP: one doctor profile per user), `clinic_id`, specialization, bio, license. Calendar visibility comes from this row, NOT the `doctor` role. (Faz 3 multi-clinic: → `clinic_doctor` pivot, UNIQUE relaxes.)
- `patients` — clinic-owned RECORD: `clinic_id` NOT NULL, `user_id` nullable (always null in MVP — no patient login until Faz 2). `phone` unique per clinic. The same person across clinics = multiple `patients` rows.

Staff↔clinic membership is the Spatie Teams role assignment (`clinic_id` on `model_has_roles`), NOT a `users` column; `doctors`/`patients` profiles are an additional layer, not the membership link. One identity wears multiple hats (doctor at A, patient at B) — isolation comes from `ClinicScope` + clinic-scoped roles (a role at clinic A grants nothing at B).
