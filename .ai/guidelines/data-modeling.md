# Data modeling conventions

- Primary keys are autoincrement bigint — never UUIDs. Every table has `created_at`/`updated_at`.
- All datetime columns use PostgreSQL `timestamptz` (timestamp with time zone) — in migrations use `timestampsTz()` and `timestampTz()` (e.g. `starts_at`/`ends_at`, `completed_at`, `accepted_at`). The application timezone stays UTC.
- Money columns are `decimal(12,2)`; geo coordinates are `decimal(10,7)`.
- Compute monetary totals in the application layer, never as DB generated columns (e.g. `total_amount = subtotal_amount - discount_amount`; `subtotal = max(0, quantity*unit_price - discount_amount)`).
- Snapshot price (`unit_price`) onto pivot/line rows so historical records keep their original prices when catalog prices change.
- Treat catalog FKs (`service_id`, `product_id`) as soft references: soft-deleting a catalog row must not break linked historical snapshots. Use `ON DELETE CASCADE` only on treatment line pivots (`treatment_services`/`treatment_products`) → `treatments`.
- Use polymorphic `morphTo` with a STRING morph-map slug (never class names) for: `treatments.details`, `state_logs.loggable`, `sms_logs.loggable`, `consents.consentable`, `field_values.entity`.
- `doctors.user_id` is UNIQUE (a user maps to at most one doctor profile). `patients.phone` is unique per clinic via composite `UNIQUE(clinic_id, phone)`; `patients.user_id` is nullable (always null in MVP).
- `users.email` and `users.phone` are both nullable + unique; enforce "at least one present" in the Service layer.
- Store clinic schedules/breaks in `clinics.working_hours` JSON; `verticals.config` is JSON.
- Add forward-compat nullable columns now, always null in MVP: `clinics.organization_id` (Faz 3 multi-branch), `appointments.appointment_type_id` (Faz 2).
