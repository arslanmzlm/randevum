# Multi-tenancy

- One clinic = one tenant (1:1) in MVP. Active clinic is bound per request via `SetClinicContext` (from the authed user); the public site derives vertical/clinic from the host header.
- Clinic-owned models use the `BelongsToClinic` trait: global `ClinicScope` + auto-set `clinic_id` on create from the active clinic (`ClinicContext`, set by `SetClinicContext`); no active clinic = no filter.
- `clinics.tenant_id` is a NOT NULL FK. Every operational table (`patients`, `appointments`, `treatments`, `cases`, `services`, `transactions`, `sms_logs`, `status_logs`, ...) carries a NOT NULL `clinic_id`.
- Never put `clinic_id`/`tenant_id` on `users` — staff↔clinic membership is the Spatie Teams role assignment (see identity-membership).
- Adding a module must never alter base/shared tables; module tables stay separate.
