<p align="center">
  <img src="public/favicon.svg" alt="Randevum" width="88">
</p>

<h1 align="center">Randevum</h1>

<p align="center">
  Multi-tenant clinic management SaaS for Turkey. Appointments, patient records, treatments, billing and SMS in one panel. Laravel 13 + Inertia v3 + Vue 3, built as a modular monolith with per-clinic tenancy and permission-backed authorization.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue.svg" alt="License: AGPL-3.0"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/laravel-13-red.svg" alt="Laravel 13"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/php-8.4-777bb4.svg" alt="PHP 8.4"></a>
  <a href="package.json"><img src="https://img.shields.io/badge/vue-3-42b883.svg" alt="Vue 3"></a>
  <a href="tests"><img src="https://img.shields.io/badge/tests-2633%20passing-green.svg" alt="2633 tests"></a>
</p>

<p align="center">
  <a href="README.tr.md">Türkçe</a>
</p>

---

> **Status: on hold, archived as a portfolio project.**
> The B2B core and the first expansion wave are finished and green (2633 Pest tests). Development
> stopped in August 2026, before launch, for a regulatory reason described in
> [Why development stopped](#why-development-stopped). There are no live customers and no patient
> data has ever been in this system.

## What is Randevum?

Randevum is the clinic-facing half of a vertical SaaS: one Laravel monolith that a clinic uses to
run its day. Reception books appointments against a doctor calendar, the doctor records the case
and treatment, the front desk collects payment in installments, and the system sends the reminder
SMS. Patients belong to a clinic, never to the platform.

The architecture is built around two ideas that shape almost every file:

**One clinic is one tenant.** Every operational table carries a `clinic_id`, and clinic-owned
models use a global scope that is fail-closed. A query without an active clinic returns nothing
rather than everything. Tenant isolation is not a convention here, it is a test requirement: every
CRUD feature ships a test proving clinic A cannot read or mutate clinic B's rows.

**A vertical is a folder.** Podiatry is the only vertical that shipped, and it lives entirely under
`app/Modules/Verticals/Podiatry` with its own config, language files, migrations and seeders. The
patient-facing vocabulary (patient, client, customer) comes from the vertical's language file, so a
second vertical changes the words without touching the domain code.

## Why development stopped

On 29 March 2025 Turkey's regulation on the independent practice of health professions
(Sağlık Meslek Mensuplarının Serbest Meslek İcrası Hakkında Yönetmelik) made podiatry, along with
dietetics, psychology and physiotherapy, a licensed health service. Article 18 requires patient
records to be kept in a health information system registered in the Ministry of Health's
registration system (KTS) and transferred to the central health data system (e-Nabız).

Legally the product is not an "appointment app", it is an MBYS (a practice information management
system), because it stores anamnesis and treatment records. Registration requires company-level
ISO 27001 through a TÜRKAK-accredited body plus SPICE Level 2 (or CMMI Level 3), then a Ministry
software audit and an e-Nabız integration. Article 16 additionally forbids storing health service
data outside Turkey, which rules out the usual cloud defaults.

For a two-person team without a company yet, the certification cost and the 9 to 18 month timeline
are larger than the product. Demos and pilots are unaffected by the rule, but paid sales to a
licensed clinic are not possible before registration, so the project was shelved rather than
half-launched. The compliance work that was queued (field-level encryption, access audit logging,
2FA, retention automation, data export) is listed under [What is not built](#what-is-not-built).

## Features

Everything below is implemented, tested and merged.

### Scheduling

- Month, week and day calendar with a doctor column per day view, minute-positioned appointment
  chips, overlap lanes, closed-hour and break bands, per-doctor leave and a now-line. Written from
  scratch rather than on a calendar library.
- Appointment creation with walk-in support, conflict detection against working hours, doctor leave
  and existing bookings, and appointment types that colour the calendar.
- Confirm, cancel and reschedule flows with a status log, plus bulk cancellation to close a day.
- Bulk creation for reception, one patient with N appointments.
- Doctor availability and leave, and a one-step doctor offboarding that cancels the affected
  appointments and notifies the patients.

### Clinical

- Patient records with search, notes, tags and CRM segments, last-visit and outstanding-balance
  columns.
- Case management grouping treatments under a clinical thread.
- Treatment recording with complaint, diagnosis and process fields, clinic service templates that
  prefill them, service and product lines with snapshot pricing, and stock consumption.
- Void for a completed treatment: line-level stock return, blocked while payment is outstanding,
  removed from revenue retroactively.
- Anamnesis with a fixed per-vertical schema, digital consent and a printable PDF.
- Before and after media on a treatment, including HEIC conversion.
- Follow-up records tied to a case, with a preview that lets a package's future appointment dates
  be edited row by row against an availability badge.

### Money

- Partial payments, patient balance, refunds and installment plans with calendar tracking.
- Manual income and expense entries, stock movement history with typed reasons.
- Revenue reports with presets, payment-method and time breakdowns, Excel export and a tag-based
  cache that drops itself when money moves.

### Messaging

- SMS gate with per-type clinic toggles, so a clinic decides which sends it wants.
- Automatic 24-hour and 1-hour appointment reminders, status-change messages, per-clinic templates,
  a delivery log screen and a monthly quota with a hard block. OTP always bypasses the gate.

### Platform

- Email and password login through Fortify, with phone and SMS OTP as a second passwordless option.
  Phone verification is deferred until a patient's first booking rather than blocking signup.
- Nine baseline roles across global and clinic-scoped tiers, backed by Spatie Permission with the
  Teams feature keyed on `clinic_id`.
- A permission matrix screen where a clinic customises roles. Baseline roles are global templates
  that get copy-on-written into a clinic-owned row on first edit, so one clinic's changes never
  touch another's. Custom roles start empty and are clinic-private. A server-side guard prevents a
  user from stripping their own ability to manage roles.
- Multi-branch support, clinic profile with working hours, logo variants and a map location.
- Turkish and English throughout, both server side and in the Vue layer.
- Legal document versioning with consent capture at signup.
- Encrypted, monitored database backups, Horizon for queues, Pulse for application metrics.

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Database | PostgreSQL 16 (`timestamptz`, `jsonb`, partial indexes) |
| Cache, queue, sessions | Redis |
| Frontend | Inertia.js v3, Vue 3, TypeScript, Tailwind CSS v4, PrimeVue v4 (Aura preset) |
| Typed routes | Laravel Wayfinder |
| Auth | Fortify (session) + Sanctum (API tokens), Spatie Permission with Teams |
| Queues, monitoring | Horizon, Pulse |
| Media | Spatie Media Library, Imagick with a HEIC delegate |
| Documents | Spatie PDF + Browsershot, maatwebsite/excel |
| Backups | Spatie Backup, encrypted archives to S3-compatible storage |
| Icons | Tabler |
| Dates | date-fns and date-fns-tz for the UTC to clinic-timezone hop, native `Intl` for display |
| Tests | Pest v4, 2633 tests including browser smoke tests |
| Local runtime | DDEV (nginx, PHP 8.4, PostgreSQL 16, Node 24, pnpm via corepack) |
| Code style | Pint, ESLint v9, Prettier, `vue-tsc` |

## Architecture

### Modular monolith

Backend code is organised by business domain under `app/Modules/`, never by UI surface. There is no
`Clinic/`, `Admin/` or `Api/` split, because a clinic screen and an admin screen touching the same
data belong to the same module.

| Module | Owns |
| --- | --- |
| `Core` | Shared kernel: clinic context, toasts, status logs, base exceptions |
| `Identity` | Users, doctors, roles, permission matrix, clinic membership |
| `Scheduling` | Appointments, calendar, availability, conflicts, leave |
| `Medical` | Patients, cases, treatments, anamnesis, follow-ups |
| `Catalog` | Services, products, stock |
| `Billing` | Transactions, balance, refunds, installment plans, expenses |
| `Messaging` | SMS providers, templates, queue, quota, logs |
| `Media` | Uploads, conversions, private disk routing |
| `Compliance` | Legal documents and consents |
| `Reporting` | Analytic aggregates and exports |
| `Verticals` | Self-contained vertical packages (Podiatry) |

Eloquent models stay flat in `app/Models/` per Laravel convention. A module owns a model through
its services and repositories, not through file location.

Layering is `Controller → FormRequest → Service → Repository → Model`. Controllers stay thin,
business logic lives in services, query logic in repositories.

### Module boundaries are enforced by tests

Cross-module communication goes through a service contract (synchronous, when you need data back)
or a domain event (asynchronous, fire and forget). A module never imports another module's model,
repository or concrete service.

A contract lives in the `Contracts/` folder of the module that fulfils it, and is fulfilled by a
thin class dedicated to that seam, not by the module's orchestration service. That rule exists
because the alternative produced a container cycle severe enough to take a laptop out with an OOM.
`tests/Feature/ArchTest.php` and `tests/Feature/ContainerCycleTest.php` enforce both rules, and CI
fails the build on a violation. Justified exceptions live in a named allow list with a reason.

`Reporting` is the single documented exception: its repositories may join another module's tables
directly for analytic aggregates. Tables only, still no imports, and a narrowing arch rule keeps it
honest.

### Authorization

Features are authorized on permissions, never on role names. Policies call `$user->can('<ability>')`
and the frontend reads the same shared permission list through a `useCan()` composable, gating each
control by the exact ability the server enforces. There are no ad-hoc `canManage` or `canDelete`
booleans passed down from controllers, because those drift away from what the server actually
checks and end up showing a button that 403s.

Ownership decisions that cannot be expressed as a permission, such as a doctor editing their own
profile, stay as ownership logic inside the policy alongside the permission check.

Since permissions and roles are global rows and only the role-to-user assignment is clinic-scoped,
setting the permission team ID once per request makes every `can()` call automatically
clinic-scoped.

### Frontend

Vue pages live in `resources/js/pages`, mirroring controller namespaces. Shared definitions live in
one place: a status to colour map is a `utils/` module plus one small component, never re-inlined
per page, so colours and labels cannot drift between screens. Form fields go through a single
`FormField.vue` wrapper that injects the input id and invalid state into the slotted PrimeVue
control. Large form pages split into field partials that share the Inertia form through
provide/inject rather than props, which keeps `vue/no-mutating-props` satisfied.

Every destructive action goes through a confirm dialog. Feedback is a flash toast, and a failed
validation shows one generic toast rather than one per field, since the inline field errors are
already the per-field signal.

Dark mode is wired end to end (PrimeVue `darkModeSelector`, a `.dark` class on `<html>`, design
tokens instead of hardcoded colours) but deliberately not switched on.

## Project layout

```
app/
├── Enums/                    # String-backed enums, mirrored as TS unions where the FE branches
├── Models/                   # Flat, Laravel convention
├── Modules/
│   ├── Core/                 # Shared kernel, importable everywhere
│   ├── <Domain>/
│   │   ├── Contracts/        # Cross-module seams this module fulfils
│   │   ├── Services/         # Business logic
│   │   ├── Repositories/     # Query logic
│   │   ├── Http/             # Controllers + form requests
│   │   ├── Events/ Listeners/ Jobs/
│   │   └── <Domain>ServiceProvider.php
│   └── Verticals/Podiatry/   # Self-contained: config, lang, migrations, seeders
├── Policies/
└── Scopes/                   # ClinicScope (fail-closed)
database/
├── migrations/               # Central, global ordering
└── seeders/                  # Baseline (idempotent) + Demo* fixtures
lang/{tr,en}/                 # Server-side strings, validation attribute names
resources/js/
├── pages/                    # Inertia pages
├── components/               # FormField, DataTableWrapper, calendar/, per-feature folders
├── composables/              # useCan, useDateTime, useTableFilters, useCalendarEvents
├── locales/                  # One file per locale, registered in i18n.ts
├── utils/                    # datetime, calendarLayout, status maps
└── types/                    # enums.ts mirrors PHP enums by hand
routes/                       # Split by domain: auth, admin, clinic, billing, messaging, reporting
tests/
├── Feature/                  # Majority, incl. ArchTest + ContainerCycleTest + tenant isolation
├── Browser/                  # Pest v4 smoke tests through real Chromium
└── Unit/
.ai/
├── guidelines/               # Cross-cutting invariant rules, merged into the agent instructions
└── skills/domain-rules/      # Per-domain business rules, loaded on demand
```

## Getting started

### Prerequisites

DDEV is the supported path and brings everything with it. Without DDEV you need PHP 8.4,
Composer 2, Node 24 with pnpm through corepack, PostgreSQL 16, Redis, and Imagick with a HEIC
delegate if you want iPhone photo uploads to render.

### Quick start with DDEV

```bash
git clone https://github.com/arslanmzlm/randevum.git
cd randevum
cp .env.example .env
ddev start                       # first run writes to /etc/hosts via sudo
ddev composer install
ddev pnpm install
ddev php artisan key:generate
ddev php artisan migrate --seed
ddev pnpm run build
```

The app is at `https://randevum.ddev.site` (or `https://randevum.test` if your global DDEV config
sets `project_tld: test`).

`migrate --seed` loads baseline data only: roles, permissions, countries, cities, verticals,
anamnesis field definitions and legal documents. All of it is idempotent, so re-running heals drift
instead of duplicating rows.

### Demo data

For a populated clinic with staff, catalog, patients and a full operational history:

```bash
ddev php artisan db:seed --class=DemoDatabaseSeeder

# or, to re-centre all the date-relative fixtures on today:
ddev php artisan migrate:fresh --seed --seeder=DemoDatabaseSeeder
```

The dataset is sized so every screen has both a populated and an empty state to look at. All demo
accounts use the password `password`:

| Account | Role |
| --- | --- |
| `owner@podosen.test` | Clinic owner |
| `manager@podosen.test` | Manager |
| `doctor@podosen.test` | Doctor |
| `reception@podosen.test` | Receptionist |
| `assistant@podosen.test` | Assistant |
| `superadmin@randevum.test` | Platform superadmin |

### Development

```bash
ddev pnpm run dev                # Vite dev server
ddev php artisan horizon         # queue workers (reminders, SMS, media conversions)
```

### Tests, lint and types

```bash
ddev php artisan test --parallel   # 2633 tests, ~30s on 24 processes
ddev composer lint                 # Pint
ddev pnpm run lint                 # ESLint
ddev pnpm run format               # Prettier
ddev pnpm run types:check          # vue-tsc
```

CI runs the same checks on every push to `main` (`.github/workflows/`).

## Configuration

Full inline guidance is in [`.env.example`](.env.example). The variables that matter most:

| Variable | Purpose | Default |
| --- | --- | --- |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | Default and fallback locale | `tr` / `en` |
| `DB_CONNECTION` | PostgreSQL in dev and production | `pgsql` |
| `SMS_PROVIDER` | `netgsm`, `log` (writes to `storage/logs/sms.log`), or `null` | `null` |
| `PLATFORM_SMS_MONTHLY_QUOTA` | Monthly SMS allowance per clinic, hard blocked at the limit | `1000` |
| `MEDIA_PRIVATE_DISK_DRIVER` | `local` in dev, `s3` in production. Never served by URL. | `local` |
| `AWS_ENDPOINT` | S3-compatible endpoint. Health data must stay in Turkey, so production points at a Turkish provider rather than AWS. | empty |
| `BACKUP_DESTINATION_DISK` | `local` in dev, `s3` in production | `local` |
| `BACKUP_ARCHIVE_PASSWORD` | Required in production. An unencrypted backup on object storage is a patient data leak. | empty |
| `IMAGE_DRIVER` | `imagick`, needed for HEIC | `imagick` |

Clinic-level settings (timezone, locale, currency, working hours, SMS preferences) are database
columns on the clinic, not environment variables. Nothing in the code assumes a single country.

## Development process

This codebase was built with an AI-assisted pipeline, and the parts of it that live in the repo are
worth pointing at because they shaped the design more than any framework choice did.

`.ai/guidelines/` holds cross-cutting invariants as terse imperative rules: architecture, tenancy,
data modelling, auth, i18n, component reuse, testing. They are merged into the agent instruction
file and loaded on every turn. `.ai/skills/domain-rules/` holds per-domain business rules loaded on
demand, one file per domain.

The discipline that made it work is that a rule is only worth writing if something enforces it.
Module boundaries, the container-cycle rule and the tenancy scope are all backed by tests that fail
CI, not by prose. Where a rule could not be enforced mechanically, it was written as one line with
the reason moved out of the way, so the rules stay short enough to be read every time.

## What is not built

The B2B core and the Phase 2 clinic expansion are complete. What stopped mid-queue:

**Compliance wave (queued, not started).** Field-level encryption for special-category data, patient
identity numbers (TCKN, foreign ID, passport) with an HMAC lookup hash, an access audit log
covering reads, email verification plus SMS-based 2FA, consent revocation and per-patient consent,
media lifecycle and retention automation, and data export for both clinic offboarding and the
patient's statutory access right. The order is deliberate: encryption first, so the audit log and
the export are built on encrypted fields rather than retrofitted onto plaintext.

**Never started.** The B2C side (patient mobile app and public marketplace, roughly 15 features),
platform billing and subscriptions, superadmin tooling, and the e-Nabız integration itself.

**Not a feature.** A production deployment environment. The application has never run outside DDEV
and CI.

## License

Randevum is released under the **GNU Affero General Public License v3.0**, see [`LICENSE`](LICENSE).

You may use, modify and self-host it freely. If you run a modified version as a network service,
you must release your modifications under AGPL-3.0.
