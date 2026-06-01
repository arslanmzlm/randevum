---
name: domain-rules
description: "Project-specific business-domain rules for this clinic/appointment SaaS (Platform360 / randevum). Activate WHENEVER writing, reviewing, or planning code that touches a business domain: clinical records (cases & treatments, notes, vertical detail tables), scheduling & appointments (availability, conflicts, walk-ins, doctor leave), payments/billing & stock (transactions, balance, refunds, payment providers), SMS/messaging (providers, queues, reminders, status SMS), media (uploads, conversions, S3, retention), anamnesis & dynamic EAV forms, verticals, deletion/edit windows & KVKK retention, identity & clinic-membership layering (users/doctors/patients), state machines & status transitions, or runtime/ops (queues, Horizon, scheduler, backups). Read the matching rules/<domain>.md before implementing. Cross-cutting invariants (multi-tenancy, data modeling, auth, i18n, ddev, architecture) stay in CLAUDE.md and are NOT in this skill. Project rules here override generic laravel-best-practices."
metadata:
  author: arslanmzlm
---

# Domain rules — Platform360 / randevum

Project-specific rules for each business domain. Cross-cutting invariants live in CLAUDE.md (always
loaded); this skill holds the per-domain detail, loaded on demand.

## How to use

When your work touches a domain below, **read its `rules/<domain>.md` file before writing or
reviewing** — don't work from memory. Read only the files for the domains you actually touch. When a
rule here conflicts with generic `laravel-best-practices`, **this skill wins** (it encodes decided
project conventions, not defaults).

## Quick reference

### Cases & treatments → `rules/cases-treatments.md`
- Cases are doctor-centric, never patient-visible; appointment 1:1 treatment; treatment → 0..1 case
- Vertical fields in a per-vertical morphTo detail table; three note levels; service auto-fills complaint/diagnosis/treatment_process templates

### Scheduling & appointments → `rules/scheduling-appointments.md`
- 3-layer availability check (working hours − breaks / schedule_exceptions / overlapping appointments)
- schedule_exceptions are per-doctor; walk-ins bypass layers 2 & 3; effective-end overlap calc; doctor leave never auto-cancels (preview → owner confirm)

### Payments, balance & stock → `rules/payments-stock.md`
- transactions status enum + nullable treatment_id; never store balance — always derive it
- partial payment = multiple transactions; refunds = negative counter-entry (never hard-deleted); online behind PaymentProviderInterface; stock deducts on completed, returns on voided (may go negative)

### Messaging / SMS → `rules/messaging-sms.md`
- All SMS through `SmsProviderInterface` + `SendSmsJob` on the `sms` queue — never synchronous
- Log every send to sms_logs (polymorphic, snapshot phone/body); status-change SMS event-driven; reminder scheduler every 5 min with 24h/1h windows

### Media → `rules/media.md`
- Spatie Medialibrary + spatie/image + S3; exactly 3 queued conversions (large/medium/thumb), WebP 80–85, always keep the original
- Writes go through `MediaServiceContract`; access control is tenant+clinic-prefixed; read-only URLs via the `HasImageUrls` trait

### Anamnesis & dynamic fields → `rules/anamnesis-fields.md`
- EAV (`fields` + `field_values`), `entity_type` patient|treatment; titles/labels ALWAYS from lang keys
- Layered ownership (vertical defaults → clinic overrides); a clinic never reads another clinic's anamnesis; MVP: tables exist but empty

### Verticals → `rules/verticals.md`
- Read the vertical list from the DB; clinics are vertical-bound & immutable; patient accounts are vertical-independent
- Add a vertical by copying the module folder (config + lang + seed + morph-map slug); never edit global config

### Deletion, edit windows & retention → `rules/deletion-retention.md`
- Drive all edit/delete windows from `config/platform.php`, read in the Service layer; no hard delete by default
- Treatments/cases never hard-deleted; transactions immutable (1h then counter-entry); KVKK media retention; legal documents versioned immutably

### Identity & clinic membership → `rules/identity-membership.md`
- Three layers: users (global auth, no clinic_id) / doctors (profile, user_id UNIQUE) / patients (clinic-owned record)
- Staff↔clinic membership = the Spatie Teams role assignment, never a users column

### State machines & transitions → `rules/state-machines.md`
- A `status` column on every state-bearing entity + log every transition to status_logs; back each status/value enum with a string-backed `app/Enums/*` (TitleCase case, lowercase value)
- All transition rules in the Service layer; treatment status drives stock/balance side effects; spelling is always `Cancelled`

### Runtime & operations → `rules/runtime-operations.md`
- Async work (SMS, email, media conversion) never runs synchronously; Horizon supervisors default/sms/media/high
- Horizon/Pulse dashboards admin-only; scheduler every minute; daily S3 backups; `/up` health endpoint; AWS Frankfurt (eu-central-1)
