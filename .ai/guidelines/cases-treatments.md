# Cases & treatments

- Cases are doctor-centric and never visible to patients. `appointments.case_id` and `treatments.case_id` are nullable; `treatments.case_id` is denormalized from the appointment for filtering.
- Relationships: 1 patient → N cases (optional); 1 appointment → 1 treatment (mandatory); 1 treatment → 0..1 case; 1 case → N treatments.
- Treatment vertical-specific fields live in a per-vertical morphTo detail table. In MVP only `podiatry_treatment_details` exists and `details_type` is always `'podiatry'`. Enforce clinic-vertical / detail-type consistency in the Service layer, not in the DB.
- Three note levels, all distinct: `patients.notes` (patient-level), `cases.notes` (case-level), and per-visit structured fields (complaint / diagnosis / treatment_process) in the vertical detail table. `treatments.notes` is an extra free note, not a replacement for the structured fields.
- Selecting a service auto-fills complaint/diagnosis/treatment_process from `services.default_*` templates as an editable starting point.
