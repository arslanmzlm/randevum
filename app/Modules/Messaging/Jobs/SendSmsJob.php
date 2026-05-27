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

/**
 * The single path for sending SMS: records an sms_logs row (queued), sends via the
 * bound provider, then settles the row to sent/failed. Runs on the dedicated `sms` queue.
 */
final class SendSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly SmsMessage $message)
    {
        $this->onQueue('sms');
    }

    public function handle(SmsProviderInterface $provider): void
    {
        $log = SmsLog::create([
            'clinic_id' => $this->message->clinicId,
            'patient_id' => $this->message->patientId,
            'phone' => $this->message->phone,
            'type' => $this->message->type,
            'loggable_type' => $this->message->loggableType,
            'loggable_id' => $this->message->loggableId,
            'body' => $this->message->body,
            'status' => SmsStatus::Queued,
            'scheduled_at' => now(),
        ]);

        $response = $provider->send($this->message->phone, $this->message->body);

        // provider_ref is kept even on failure — providers may return a tracking id with an error.
        $log->update([
            'status' => $response->successful ? SmsStatus::Sent : SmsStatus::Failed,
            'provider_ref' => $response->reference,
            'error' => $response->error,
            'sent_at' => $response->successful ? now() : null,
        ]);
    }
}
