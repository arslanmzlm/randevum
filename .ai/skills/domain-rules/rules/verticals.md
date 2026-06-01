# Verticals

- Read the vertical list from the DB (`verticals` table). Never hardcode vertical values in code.
- Clinics are vertical-bound: `clinics.vertical_id` is NOT NULL and immutable (one clinic = one vertical). Doctors inherit their vertical via their clinic. Denormalize `vertical_id` onto `cases`/`services` (and bind it on `products`, `fields`) for filtering.
- Patient accounts are vertical-independent; a patient's "my appointments" merges across verticals.
- Add a new vertical by copying `app/Modules/Verticals/<Name>/`, filling its config + lang + seed, adding a `verticals` row and a morph-map slug. Never edit global `config/` to add a vertical.
- Keep vertical-specific settings (color, icon, labels, default slot minutes) in the module's own `config/<vertical>.php` and `lang/`. Only vertical-agnostic settings belong in top-level `config/platform.php`.
- Vertical display names and entity labels come from i18n lang files, not from DB columns.
