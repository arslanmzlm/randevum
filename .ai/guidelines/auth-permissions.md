# Authentication & permissions

## Auth

- Primary auth is Laravel Fortify with EMAIL + password (no username — username causes friction/collisions). One central login page for all roles, with role-based redirect after login. Accounts are vertical-independent.
- Phone + SMS OTP is a SECOND login option (passwordless), offered alongside email+password. It requires a verified phone.
- Phone verification is NOT done at registration. It is required when a user books their FIRST appointment (SMS OTP → sets `phone_verified_at`), so reminders have a verified number without adding signup friction. A verified phone is also what unlocks the phone+OTP login option.
- Sanctum backs the B2C clients: one shared API for Ionic mobile (token auth) and Inertia web (session auth); patient web pages under `resources/js/Pages/Patient/*`.

## Roles & permissions (Spatie Permission + Teams)

- Use Spatie Permission with its DEFAULT tables, with the **Teams** feature enabled. Set `'teams' => true` and `'team_foreign_key' => 'clinic_id'` in `config/permission.php` BEFORE running the permission migration (Teams adds a `clinic_id` column to `roles`/`model_has_roles`/`model_has_permissions`). Teams is part of `spatie/laravel-permission`, not a separate package.
- A request-scoped middleware sets the active clinic via `setPermissionsTeamId($clinicId)` (derived from the user's current clinic/membership); on clinic switch, unset the user's role/permission relations.
- Role scoping:
  - **Global** (`clinic_id` null): `superadmin` (platform), `patient` (B2C — permissions are over the user's own data, not a clinic's).
  - **Clinic-scoped** (`clinic_id` set): `owner`, `manager`, `doctor`, `receptionist`, `assistant`.
- Seed these baseline roles (faz-0): `superadmin`, `owner`, `manager`, `doctor`, `receptionist`, `assistant`, `patient`. Define roles globally; clinic-scoped assignments carry `clinic_id`.
- Baseline role intent:
  - `owner` — clinic/business admin: full clinic access + settings, billing, staff & doctor management, refunds.
  - `manager` — branch operations: staff scheduling, catalog, view billing, manage appointments/patients — but NOT business-level settings, clinic creation, or owner assignment.
  - `doctor` — own appointments/treatments, own `schedule_exceptions`, catalog read. Calendar visibility requires a `doctors` profile row, not the role.
  - `receptionist` — front desk (non-clinical): appointments CRUD, patient register/edit/notes, take payments; NO clinical treatment depth, billing settings, doctor management, or refunds.
  - `assistant` — clinical support (treatment room): fill anamnesis/treatment fields, add treatment media, clinical patient view; NO billing/refund or staff management.
  - `patient` (Faz 2) — own profile, own appointments, booking; assigned to patient user accounts from Faz 2 (MVP patient records have no login).
- MVP authorization is seed-driven. There is NO owner-facing permission-management UI in MVP — custom roles, user-level overrides, self-lockout protection, and the permission matrix are Faz 3. Until then, superadmin grants clinic-specific permissions directly in the DB (Spatie is DB-driven, no deploy needed).
- Custom/dynamic roles (Faz 3) are created at runtime scoped to the creating clinic (`clinic_id`), so one clinic's custom role is invisible to other clinics.
