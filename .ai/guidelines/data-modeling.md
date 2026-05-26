# Data modeling conventions

- Primary keys are autoincrement bigint — never UUIDs. Every table has `created_at`/`updated_at`.
- All datetime columns use PostgreSQL `timestamptz` — in migrations `timestampsTz()`/`timestampTz()` (e.g. `starts_at`/`ends_at`, `completed_at`, `accepted_at`). App timezone stays UTC.
- Money columns are `decimal(12,2)`; geo coordinates `decimal(10,7)`.
- Compute monetary totals in the app layer, never as DB generated columns (`total_amount = subtotal_amount - discount_amount`; `subtotal = max(0, quantity*unit_price - discount_amount)`).
- Snapshot price (`unit_price`) onto pivot/line rows so historical records keep their original prices when catalog prices change.
- Treat catalog FKs (`service_id`, `product_id`) as soft references: soft-deleting a catalog row must not break linked snapshots. Use `ON DELETE CASCADE` only on treatment line pivots (`treatment_services`/`treatment_products`) → `treatments`.
- Polymorphic `morphTo` always uses a STRING morph-map slug (never class names): `treatments.details`, `state_logs.loggable`, `sms_logs.loggable`, `consents.consentable`, `field_values.entity`.
- `doctors.user_id` UNIQUE; `patients.phone` unique per clinic via `UNIQUE(clinic_id, phone)`; `patients.user_id` nullable (always null in MVP).
- `users.email` and `users.phone` both nullable + unique; enforce "at least one present" in the Service layer.
- `clinics.working_hours` and `verticals.config` are JSON.
- Forward-compat nullable columns now, always null in MVP: `clinics.organization_id` (Faz 3 multi-branch), `appointments.appointment_type_id` (Faz 2).
