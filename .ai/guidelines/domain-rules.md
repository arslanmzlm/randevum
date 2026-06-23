# Domain rules live in the `domain-rules` skill

Business-domain rules are NOT in `.ai/guidelines/` — they live in the **`domain-rules`** skill, loaded on demand:
clinical (cases/treatments), scheduling & appointments, payments/billing & stock, SMS/messaging,
media, anamnesis & EAV forms, verticals, deletion/edit windows & retention (KVKK), identity &
clinic-membership layering, state machines & status transitions, runtime/ops (queues, Horizon,
scheduler, backups).

Before writing, reviewing, or planning code that touches one of those domains, activate the
`domain-rules` skill and read the matching `rules/<domain>.md` — don't work from memory. Pipeline
phase agents must invoke it explicitly (skills don't auto-activate inside an agent). When a rule in
that skill conflicts with generic `laravel-best-practices`, the project rule wins.
