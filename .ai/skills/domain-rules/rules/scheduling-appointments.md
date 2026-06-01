# Scheduling & appointments

- Appointments and `schedule_exceptions` both store `starts_at`/`ends_at` (timestamptz).
- Availability is a 3-layer check: (1) within clinic `working_hours` (minus breaks); (2) no overlapping `schedule_exceptions` for the doctor; (3) no overlapping appointment for the doctor with status `Confirmed`/`Arrived`. One shared conflict-check for B2B + B2C booking.
- `schedule_exceptions` are per-doctor, referencing the doctor PROFILE (`doctors.id`, same target as `appointments.doctor_id`). A clinic-wide closure creates N per-doctor rows — there is no clinic-wide exception entity.
- Walk-ins set `is_walk_in = true` and bypass layers 2 & 3 (only working-hours applies), but follow the normal flow (treatment, status_logs, reports).
- Each appointment creates exactly one treatment (1:1; `appointment_id` UNIQUE on treatments), created as `Draft` when the appointment reaches `Arrived`, filled in the Process screen, `Completed` on submit.
- Effective end for overlap detection = `starts_at + (duration − 1 minute) + 59 seconds`. Resolve `duration` by priority: service → appointment type → `clinics.default_slot_duration_minutes`.
- Doctor leave overlapping `Confirmed` appointments NEVER auto-cancels: preview → owner confirmation → transition to `Cancelled` (logged + cancellation SMS).
