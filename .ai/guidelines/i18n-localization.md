# i18n & localization

- Always use the translate helper (`__('...')`) for user-facing strings — even when no translation exists yet. Never hardcode literal Turkish (or any) UI strings.
- Source all patient/entity terminology (`hasta`/`danışan`/`müşteri`, doctor/patient labels) from lang keys, e.g. `__('verticals.<slug>::vertical.patient_label')`. Each vertical ships `lang/tr/vertical.php` + `lang/en/vertical.php` with `name`, `patient_label`, `doctor_label`.
- Mirror frontend strings via `vue-i18n`.
- DB timestamps are ALWAYS stored in UTC (Laravel default) — do not override.
- Never assume a single country. Always derive timezone, locale, currency, and date/time/money formatting from config — never from inlined constants.
- Read locale context from `clinics.timezone` / `clinics.locale` / `clinics.currency` columns (MVP defaults `Europe/Istanbul`, `tr_TR`, `TRY`). Do not hardcode those defaults in code.
