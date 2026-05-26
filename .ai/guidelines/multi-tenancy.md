# Multi-tenancy

- One clinic = one tenant (1:1) in MVP. Tenant context is bound per request via `SetTenantContext` middleware (derived from the authenticated user). The public site derives vertical/clinic context from the host header.
- Tenant-owned models use the `BelongsToTenant` trait: a global `TenantScope` plus auto-setting `tenant_id` on `creating`.
- `clinics.tenant_id` is a NOT NULL FK. Every operational table (`patients`, `appointments`, `treatments`, `cases`, `services`, `product_stocks`, `transactions`, `sms_logs`, `state_logs`, ...) carries a NOT NULL `clinic_id`.
- Never put `clinic_id` or `tenant_id` on `users`. Staff↔clinic membership is the Spatie Teams role assignment (`clinic_id` on `model_has_roles`); `doctors`/`patients` profile tables are an additional layer, not the membership link. (See identity-membership.)
- Adding a module must never alter base/shared tables; module tables stay separate.
