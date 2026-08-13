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

## Role management UI (permission matrix, custom roles)

- `settings/roles` is the clinic's permission matrix: read gated by `roles.viewAny`, every mutation by `roles.manage` (`RolePolicy`). Server rules live in `App\Modules\Identity\Services\*`; never re-implement one in a controller or on the frontend.
- Seeded baseline rows stay GLOBAL. The first time a clinic edits a baseline role it is copy-on-written into a clinic-owned row (`RoleCustomizationService::customizeForActiveClinic`: same name/guard, permissions copied, that clinic's `model_has_roles` re-pointed) — the global template and every other clinic are untouched.
- `PermissionSeeder` syncs GLOBAL rows only (explicit `whereNull('clinic_id')` lookup, never `Role::findByName`) — re-seeding must never overwrite a clinic's customization.
- Custom roles are clinic-owned (`clinic_id` set, name is not a `ClinicRole` case), invisible to other clinics, start with ZERO permissions, and are the only roles that may be renamed or deleted. A role with any assignment in the clinic cannot be deleted.
- Baseline copies are never renamed or deleted individually; the clinic reverts them all at once back to the global templates (`revertAllForActiveClinic`), which re-points assignments and drops the copies. Custom roles are untouched by a revert.
- Self-lockout protection is server-side and mandatory (`SelfLockoutGuard`): a user may not strip `roles.manage`/`roles.viewAny` from a role THEY hold, delete their own role, or revert in a way that would do either. The matrix renders those cells locked — UX only, the guard is the gate.
- A permission created AFTER a clinic's copy-on-write and not held by the copy is surfaced as "undefined" in the matrix (owner decides) — never silently granted or denied. A permission that predates the copy is the clinic's own decision and is not flagged.

## Authorizing (permission-backed, not role-name checks)

- Authorize features on PERMISSIONS, never role names: policies/gates call `$user->can('<perm>')` and the frontend reads the shared permission list via `useCan()` — do NOT branch on `hasRole(...)` for feature authorization. (Only platform-level global gates with no permission, e.g. Pulse/Horizon `superadmin`, may check the role directly.)
- Define every permission in `database/seeders/PermissionSeeder.php` and attach it to the baseline roles with `syncPermissions` (authoritative — re-running heals drift). Add permissions INCREMENTALLY as each feature lands (only the abilities actually enforced) — never a full up-front matrix. Name them `<resource>.<ability>` (dot), aligned to the policy ability method (`doctors.update`, `clinic.update`). Run `PermissionSeeder` right after `RoleSeeder` in `DatabaseSeeder`.
- "Own-record" access (e.g. a doctor editing their own profile) stays as ownership logic IN the policy alongside the `can()` check — it cannot be expressed as a permission: `return $doctor->user_id === $user->id || $user->can('doctors.update');`.
- Permissions and roles are global rows (`clinic_id` null); the role→permission link is global, only the role→user assignment is clinic-scoped — so once `SetClinicContext` has called `setPermissionsTeamId($clinicId)`, `can()` is automatically scoped to the active clinic.
- Keep the controller `authorize()` call as the single gate (route `can:` middleware doesn't fit context-resolved resources like the active clinic, which has no route-model binding).
- **Frontend gating = `useCan()` + the exact permission, NOT per-page boolean flags.** `HandleInertiaRequests` shares the user's active-clinic permission names once as `auth.permissions` (`getAllPermissions()->pluck('name')`); pages/layout read them via the `useCan()` composable and gate each control by the SAME ability the server enforces (`v-if="can('patients.delete')"`). Do NOT pass ad-hoc `canManage`/`canDelete` booleans from controllers — that drifts and conflates abilities. Mirror the precise permission per control so a button never shows where the server then 403s. Client gating is UX only; the server still enforces with `authorize()`.
- **Ownership / instance** decisions that CANNOT be a permission (e.g. `$doctor->user_id === $user->id`, or a permission combined with state like "no own profile yet") stay as per-page Inertia props (`canEditSelf`, `canCreateOwn`) — never folded into `auth.permissions`.
- **Plan-phase permission uncertainty:** when planning a feature and you're unsure which roles a new permission should attach to (or whether an action needs its own permission at all), DON'T guess a role set — write it as an open question in the plan for the human to resolve at GATE 1. The user owns the role→permission policy.
