# Authentication & permissions

## Auth

- Primary auth is Laravel Fortify, EMAIL + password (no username). One central login page for all roles, role-based redirect after login. Accounts are vertical-independent.
- Phone + SMS OTP is a SECOND, passwordless login option; requires a verified phone.
- Phone verification is NOT at registration — it's required when a user books their FIRST appointment (SMS OTP → sets `phone_verified_at`), so reminders have a verified number without signup friction. A verified phone also unlocks phone+OTP login.
- Sanctum backs B2C clients: one shared API for Ionic mobile (token) and Inertia web (session); patient web pages under `resources/js/Pages/Patient/*`.
- **Fortify action validation is inline, NOT a FormRequest** (exception to Controller→FormRequest→Service): Fortify hands its bound action a raw `$input` array, so validate with `Validator::make(...)->validate()` (like stock `CreateNewUser`). Field display names still come from `validation.attributes` (lang), never inline.

## Roles & permissions (Spatie Permission + Teams)

- Use Spatie Permission with DEFAULT tables and the **Teams** feature. Set `'teams' => true` and `'team_foreign_key' => 'clinic_id'` in `config/permission.php` BEFORE running the permission migration (Teams adds `clinic_id` to `roles`/`model_has_roles`/`model_has_permissions`).
- A request-scoped middleware sets the active clinic via `setPermissionsTeamId($clinicId)`; on clinic switch, unset the user's role/permission relations.
- Role scoping — **Global** (`clinic_id` null): `superadmin`, `admin`, `moderator`, `patient` (B2C, over the user's own data). **Clinic-scoped** (`clinic_id` set): `owner`, `manager`, `doctor`, `receptionist`, `assistant`.
- Seed 9 baseline roles (faz-0): the global + clinic-scoped sets above. Roles are global; clinic-scoped assignments carry `clinic_id`. `patient` assigned from Faz 2. Per-role intent / permission subsets: see `.localdev/docs/data-model.md`.
- A user "is a doctor" by having a `doctors` profile row (calendar visibility), NOT by holding the `doctor` role.
- MVP authorization is seed-driven; NO permission-management UI (custom roles, overrides, self-lockout protection, matrix = Faz 3). Until then superadmin grants clinic permissions directly in the DB. Faz 3 custom roles are scoped to the creating clinic, invisible to others.

## Authorizing (permission-backed, not role-name checks)

- Authorize features on PERMISSIONS, never role names: policies/gates and the shared Inertia UI-capability flags call `$user->can('<perm>')` — do NOT branch on `hasRole(...)` for feature authorization. (Only platform-level global gates with no permission, e.g. Pulse/Horizon `superadmin`, may check the role directly.)
- Define every permission in `database/seeders/PermissionSeeder.php` and attach it to the baseline roles with `syncPermissions` (authoritative — re-running heals drift). Add permissions INCREMENTALLY as each feature lands (only the abilities actually enforced) — never a full up-front matrix. Name them `<resource>.<ability>` (dot), aligned to the policy ability method (`doctors.update`, `clinic.update`). Run `PermissionSeeder` right after `RoleSeeder` in `DatabaseSeeder`.
- "Own-record" access (e.g. a doctor editing their own profile) stays as ownership logic IN the policy alongside the `can()` check — it cannot be expressed as a permission: `return $doctor->user_id === $user->id || $user->can('doctors.update');`.
- Permissions and roles are global rows (`clinic_id` null); the role→permission link is global, only the role→user assignment is clinic-scoped — so once `SetClinicContext` has called `setPermissionsTeamId($clinicId)`, `can()` is automatically scoped to the active clinic.
- Keep the controller `authorize()` call as the single gate (route `can:` middleware doesn't fit context-resolved resources like the active clinic, which has no route-model binding).
- Gate each UI control by the SAME ability the server enforces: a shared Inertia flag (`canManage`) may only stand in for several controls when those controls map to abilities held by the same role set. When two abilities can diverge (e.g. a role that may create/edit but not delete), emit a SEPARATE flag per ability (`canDelete`) — never reuse one flag to show a button the server then 403s.
- **Plan-phase permission uncertainty:** when planning a feature and you're unsure which roles a new permission should attach to (or whether an action needs its own permission at all), DON'T guess a role set — write it as an open question in the plan for the human to resolve at GATE 1. The user owns the role→permission policy.
