# Identity & clinic membership

Three distinct layers — keep them separate:

- `users` — global auth identity (login): email (required, unique — primary login), phone (nullable, unique — phone+OTP second option), first_name/last_name, locale, timezone. NO `clinic_id`/`tenant_id` — a user can relate to several clinics over time (multi-clinic staff, B2C patient).
- `doctors` — doctor PROFILE (calendar/medical identity): `user_id` UNIQUE (MVP: a user maps to at most one doctor profile), `clinic_id`, specialization, bio, license. A user "is a doctor" by having this row, NOT by holding a role; calendar visibility comes from it. (Faz 3 multi-clinic doctor: evolves into a `clinic_doctor` pivot and `user_id` UNIQUE relaxes.)
- `patients` — clinic-owned patient RECORD: `clinic_id` NOT NULL, `user_id` nullable (always null in MVP — clinics create records manually; no patient login until Faz 2). `phone` unique per clinic. The same person across clinics = multiple `patients` rows.

Staff↔clinic membership is the Spatie Teams role assignment (`clinic_id` on `model_has_roles`), NOT a `users` column; the `doctors`/`patients` profile tables are an additional layer, not the membership link.

Same person, multiple hats: one `users` identity. A role scoped to clinic A grants nothing at clinic B. A doctor at clinic A who is treated as a patient at clinic B has a `doctors` row (A) + a `patients` row (B) under one user — isolation comes from `TenantScope` + clinic-scoped roles.
