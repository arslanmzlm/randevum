# Authentication & permissions

## Auth

- Primary auth is Laravel Fortify with EMAIL + password (no username — avoids friction/collisions). One central login page for all roles, role-based redirect after login. Accounts are vertical-independent.
- Phone + SMS OTP is a SECOND, passwordless login option alongside email+password; requires a verified phone.
- Phone verification is NOT at registration. It's required when a user books their FIRST appointment (SMS OTP → sets `phone_verified_at`), so reminders have a verified number without signup friction. A verified phone also unlocks phone+OTP login.
- Sanctum backs B2C clients: one shared API for Ionic mobile (token) and Inertia web (session); patient web pages under `resources/js/Pages/Patient/*`.

## Roles & permissions (Spatie Permission + Teams)

- Use Spatie Permission with DEFAULT tables and the **Teams** feature. Set `'teams' => true` and `'team_foreign_key' => 'clinic_id'` in `config/permission.php` BEFORE running the permission migration (Teams adds `clinic_id` to `roles`/`model_has_roles`/`model_has_permissions`). Teams ships with `spatie/laravel-permission`.
- A request-scoped middleware sets the active clinic via `setPermissionsTeamId($clinicId)` (from the user's current clinic); on clinic switch, unset the user's role/permission relations.
- Role scoping:
  - **Global** (`clinic_id` null): `superadmin` (platform), `admin` (platform ops), `moderator` (platform support/moderation), `patient` (B2C — permissions over the user's own data).
  - **Clinic-scoped** (`clinic_id` set): `owner`, `manager`, `doctor`, `receptionist`, `assistant`.
- Seed baseline roles (faz-0, 9): `superadmin`, `admin`, `moderator`, `patient`, `owner`, `manager`, `doctor`, `receptionist`, `assistant`. Roles are global; clinic-scoped assignments carry `clinic_id`. `patient` is assigned from Faz 2 (MVP patient records have no login). Per-role intent / permission subsets: see `.ai/docs/data-model.md`.
- A user "is a doctor" by having a `doctors` profile row (calendar visibility), NOT by holding the `doctor` role.
- MVP authorization is seed-driven; NO permission-management UI (custom roles, user-level overrides, self-lockout protection, permission matrix = Faz 3). Until then superadmin grants clinic permissions directly in the DB (Spatie is DB-driven, no deploy). Faz 3 custom roles are scoped to the creating clinic (`clinic_id`), invisible to others.
