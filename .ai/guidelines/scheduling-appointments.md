# Scheduling & appointments

- Appointments store `starts_at`/`ends_at` (timestamptz); `schedule_exceptions` use `starts_at`/`ends_at` too.
- Appointment availability is a 3-layer check: (1) within clinic `working_hours` (excluding breaks); (2) no overlapping `schedule_exceptions` for that doctor; (3) no overlapping appointment for that doctor with status `Confirmed` or `Arrived`. Use one shared conflict-check for both B2B manual entry and B2C online booking.
- `schedule_exceptions` are per-doctor and reference the doctor PROFILE (`doctors.id`, same target as `appointments.doctor_id`). A clinic-wide closure creates N per-doctor `schedule_exceptions` rows — there is no clinic-wide exception entity.
- Walk-ins set `is_walk_in = true` and bypass layers 2 & 3 (only the working-hours check applies), but still follow the normal flow (treatment, state_logs, reports).
- Each appointment creates exactly one treatment (1:1; `appointment_id` UNIQUE on treatments). The treatment is created as `Draft` when the appointment reaches `Arrived`, filled in the Process screen, and set to `Completed` on submit.
- Compute the effective end for overlap detection as `starts_at + (duration − 1 minute) + 59 seconds`. Resolve `duration` by priority: service → appointment type → `clinics.default_slot_duration_minutes`.
- Adding doctor leave that overlaps existing `Confirmed` appointments must NEVER auto-cancel: show a preview, require owner confirmation, then transition the affected appointments to `Cancelled` (logged + cancellation SMS).
