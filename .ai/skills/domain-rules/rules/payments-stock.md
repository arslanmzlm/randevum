# Payments, balance & stock

- `transactions` carries a `status` enum (`Pending`/`Completed`/`PartiallyRefunded`/`Refunded`; `Pending` is Faz 2 installments) and a NULLABLE `treatment_id` (a transaction need not be tied to a treatment).
- Never store balance — always derive it: paid = `SUM(transactions.amount WHERE treatment_id = X)`; balance = `treatment.total − paid`.
- Partial payment = multiple transactions on one treatment, with mixed `payment_method` (cash / card / transfer / cheque).
- Refunds are counter-entries: a NEW transaction with a negative amount; the original transaction's `status` flips to `PartiallyRefunded` or `Refunded` (fully refunded), while the treatment stays `Completed` with a refund flag. Refunds are never hard-deleted.
- Online payments go behind a `PaymentProviderInterface`. MVP has no real charge path — only cash/card/transfer tracking (a null/fake billing provider); real providers (Iyzico/Stripe) are Faz 3.
- Stock deducts when a treatment is `completed` and is returned when `voided`. Stock MAY go negative; no low-stock alerts in MVP (intentional).
- `payment_plans`/`payment_plan_items` exist in MVP but their UI is Faz 2; a paid plan item links via `payment_plan_items.transaction_id`.
