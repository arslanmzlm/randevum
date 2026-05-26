# Data modeling conventions

- Primary keys are autoincrement bigint — never UUIDs. Every table has `created_at`/`updated_at`.
- Cast every FK / `*_id` (bigint) to `integer` — Postgres returns bigint as string via PDO, breaking Vue typing (PK already int).
- All datetime columns use PostgreSQL `timestamptz` — in migrations `timestampsTz()`/`timestampTz()` (e.g. `starts_at`/`ends_at`, `completed_at`, `accepted_at`). App timezone stays UTC.
- Money columns are `decimal(12,2)`; geo coordinates `decimal(10,7)`.
- Compute monetary totals in the app layer, never as DB generated columns (`total = subtotal − discount`; `subtotal = max(0, qty*unit_price − discount)`).
- Snapshot price (`unit_price`) onto pivot/line rows so historical records keep their original prices when catalog prices change.
- Treat catalog FKs (`service_id`, `product_id`) as soft references: soft-deleting a catalog row must not break linked snapshots. Use `ON DELETE CASCADE` only on treatment line pivots → `treatments`.
- Polymorphic `morphTo` always uses a STRING morph-map slug (never class names): `treatments.details`, `status_logs.loggable`, `consents.consentable`, etc.
- `doctors.user_id` UNIQUE; `patients.phone` unique per clinic via `UNIQUE(clinic_id, phone)`; `patients.user_id` nullable (always null in MVP).
- `users.email` NOT NULL + unique (primary login); `users.phone` nullable + unique (phone+OTP). Phone columns store E.164 via `laravel-phone` `E164PhoneNumberCast:TR`; validate with `phone:TR`.
- `clinics.country_id`/`city_id` → FK `countries`/`cities` (ISO 3166-1 / TR plaka); never free text.
- `clinics.working_hours` and `verticals.config` are JSON.
- Forward-compat nullable, null in MVP: `appointments.appointment_type_id` (Faz 2). Faz 3 multi-branch = 1 `tenant` → N `clinics` (no `organization_id`).
