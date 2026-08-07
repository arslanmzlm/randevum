<?php

namespace App\Modules\Messaging\Jobs;

use App\Enums\SmsStatus;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The single path for sending SMS: records an sms_logs row (queued), sends via the
 * bound provider, then settles the row to sent/failed. Runs on the dedicated `sms` queue.
 *
 * The log row is created once, in the constructor, which only ever runs at dispatch
 * time — a queue retry/redelivery reuses the already-serialized job instance and calls
 * handle() again without re-running the constructor. That row's id travels with the
 * job so a redelivered attempt updates the SAME row instead of opening a second one,
 * and handle() can tell "already sent" apart from "never attempted".
 */
final class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public readonly int $smsLogId;

    public function __construct(public readonly SmsMessage $message)
    {
        $this->onQueue('sms');

        $this->smsLogId = SmsLog::create([
            'clinic_id' => $this->message->clinicId,
            'patient_id' => $this->message->patientId,
            'phone' => $this->message->phone,
            'type' => $this->message->type,
            'loggable_type' => $this->message->loggableType,
            'loggable_id' => $this->message->loggableId,
            'body' => $this->message->body,
            'status' => SmsStatus::Queued,
            'scheduled_at' => now(),
        ])->id;
    }

    public function handle(SmsProviderInterface $provider): void
    {
        $log = SmsLog::findOrFail($this->smsLogId);

        // A retry/redelivery of a job whose send already completed (e.g. Redis
        // `retry_after` elapses mid-flight and Horizon re-queues the same payload)
        // must not hit the provider a second time — exit quietly instead.
        if ($log->status === SmsStatus::Sent) {
            return;
        }

        $response = $provider->send($this->message->phone, $this->message->body);

        // Retry the write itself, not the whole job: if this update throws (transient
        // DB error), letting the job fail would make Horizon redeliver it — resending
        // an SMS that already went out — for the exact failure mode this guards against.
        retry(3, fn () => $log->update([
            'status' => $response->successful ? SmsStatus::Sent : SmsStatus::Failed,
            // provider_ref is kept even on failure — providers may return a tracking id with an error.
            'provider_ref' => $response->reference,
            'error' => $response->error,
            'sent_at' => $response->successful ? now() : null,
        ]), 100);
    }

    /**
     * Called once Horizon exhausts retries (supervisor-sms tries:3). Without this, a
     * dead job leaves its log row stuck at Queued forever — the panel would show it as
     * still "sending" indefinitely instead of a settled failure.
     */
    public function failed(Throwable $e): void
    {
        $log = SmsLog::find($this->smsLogId);

        // Mirror handle()'s guard: a row that already sent must never be pulled back
        // to Failed — e.g. the send succeeded but the settling update itself then threw
        // and exhausted its retries.
        if ($log === null || $log->status === SmsStatus::Sent) {
            return;
        }

        $log->update([
            'status' => SmsStatus::Failed,
            'error' => $e->getMessage(),
        ]);
    }
}
