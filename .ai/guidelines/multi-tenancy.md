# Multi-tenancy

- One clinic = one tenant (1:1) in MVP. Tenant context is bound per request via `SetTenantContext` middleware (from the authenticated user); the public site derives vertical/clinic context from the host header.
- Tenant-owned models use the `BelongsToTenant` trait: a global `TenantScope` plus auto-setting `tenant_id` on `creating`.
- `clinics.tenant_id` is a NOT NULL FK. Every operational table (`patients`, `appointments`, `treatments`, `cases`, `services`, `product_stocks`, `transactions`, `sms_logs`, `state_logs`, ...) carries a NOT NULL `clinic_id`.
- Never put `clinic_id`/`tenant_id` on `users` — staff↔clinic membership is the Spatie Teams role assignment (see identity-membership).
- Adding a module must never alter base/shared tables; module tables stay separate.
