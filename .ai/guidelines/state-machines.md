# State machines & transitions

- Every state-bearing entity keeps its current state in a `status` column (same column name across ALL entities) and logs EVERY transition to `status_logs`. Never mutate status silently.
- Back each `status` (and value enums like `gender`, `legal_documents.type`) with a PHP backed enum in `app/Enums/`, cast in the model: TitleCase cases (e.g. `Confirmed`, `NoShow`), lowercase values. DB column is `string` (never `$table->enum()`); cast + `Rule::enum()` enforce it → new values need no migration.
- `status_logs` is polymorphic (`loggable`) and records `from_status` (nullable), `to_status` (not null), `transitioned_at`, `by_user_id` (nullable — null for system/cron), `reason` (nullable).
- Enforce all transition rules in the Service layer — never scatter transition logic across controllers or models.
- Documented transitions (MVP):
  - appointments: `Pending → {Confirmed, Cancelled}`; `Confirmed → {Arrived, Rescheduled, Cancelled, NoShow}`; `Rescheduled → Confirmed`; `Arrived → {Completed, Cancelled}`; `Completed`/`Cancelled`/`NoShow` terminal. New appointments default to `Confirmed` (`Pending` is Faz 2).
  - cases: `Open → {Suspended, Closed}`; `Suspended → {Open, Closed}`; `FollowUp → {Open, Closed}`; `Closed → Open` (rare re-open). New cases default `Open`.
  - treatments: `Draft → Completed → Voided`. `Draft` = no stock deduction, not in balance; `Completed` sets `completed_at`, deducts stock, enters balance; `Voided` returns stock and adjusts balance.
  - transactions: MVP uses `Completed`/`Refunded` (`Pending` reserved for Faz 2 installments).
- Spelling is always `Cancelled` (double-l) across every enum.
