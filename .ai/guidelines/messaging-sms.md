# Messaging / SMS

- Consume every external messaging vendor through an interface so swapping vendor/region needs no migration. SMS goes through `App\Modules\Messaging\Contracts\SmsProviderInterface` (`send(string $phone, string $body): SmsResponse`); bind the concrete impl in a ServiceProvider, selected via `config('services.sms.provider')`.
- MVP impl is `NetgsmSmsProvider`; provide a `NullSmsProvider` for test/local.
- Send ALL SMS through `SendSmsJob` on the `sms` queue. Never call a provider synchronously.
- Log every send to `sms_logs` (polymorphic `loggable`): status `Queued`→`Sent`/`Failed`; if a clinic disabled SMS, write `Skipped` but still log it. Snapshot `phone` and `body` onto the row.
- Status-change SMS are event-driven (dispatched from the status transition), reusing the same pipeline.
- Reminder SMS: the scheduler runs every 5 min with two window queries (24h ±5min, 1h ±5min), filtering appointments with status `Confirmed`/`Rescheduled` and `reminder_24h_sent`/`reminder_1h_sent` = false. Set the flag after dispatch and use an `sms_logs` `status = Sent` check as a second idempotency guard. Cron/system transitions write `state_logs.by_user_id = null`.
