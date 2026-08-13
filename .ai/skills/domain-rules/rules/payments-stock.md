# Payments, balance & stock

- `transactions` carries a `status` enum (`Pending`/`Completed`/`PartiallyRefunded`/`Refunded`; `Pending` is Faz 2 installments) and a NULLABLE `treatment_id` (a transaction need not be tied to a treatment).
- Never store balance — always derive it: paid = `SUM(transactions.amount WHERE treatment_id = X)`; balance = `treatment.total − paid`.
- Partial payment = multiple transactions on one treatment, with mixed `payment_method` (cash / card / transfer / cheque).
- Refunds are counter-entries: a NEW transaction with a negative amount; the original transaction's `status` flips to `PartiallyRefunded` or `Refunded` (fully refunded), while the treatment stays `Completed` with a refund flag. Refunds are never hard-deleted.
- Online payments go behind a `PaymentProviderInterface`. MVP has no real charge path — only cash/card/transfer tracking (a null/fake billing provider); real providers (Iyzico/Stripe) are Faz 3.
- Stock deducts when a treatment is `completed`. On `void` the return is PER PRODUCT LINE, never all-or-nothing: material already consumed before the mis-entry was noticed must not go back.
- `TreatmentService::void()` takes the chosen `treatment_products.id` list as a REQUIRED argument — no implicit "return everything" default; `[]` voids without returning anything. An id that is not one of that treatment's own product lines is REJECTED, not skipped. The void UI lists every product line with the return checkbox pre-checked, and the user unchecks what was consumed.
- Stock MAY go negative; no low-stock alerts in MVP (intentional).
- `payment_plans`/`payment_plan_items` exist in MVP but their UI is Faz 2; a paid plan item links via `payment_plan_items.transaction_id`.
