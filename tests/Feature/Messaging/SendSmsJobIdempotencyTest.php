<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Data\SmsResponse;
use App\Modules\Messaging\Jobs\SendSmsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Counts provider->send() invocations so a test can assert the job hit the
 * provider exactly once even when handle() runs more than once — the shape of a
 * queue retry/redelivery, which reuses the already-constructed (already-serialized)
 * job instance rather than calling the constructor again.
 */
class CountingSmsProviderFake implements SmsProviderInterface
{
    public int $calls = 0;

    public function send(string $phone, string $body): SmsResponse
    {
        $this->calls++;

        return SmsResponse::success('provider-ref-'.$this->calls);
    }
}

it('constructing the job creates exactly one sms_logs row, before handle() ever runs', function (): void {
    new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Reminder24h,
    ));

    expect(SmsLog::withoutGlobalScopes()->count())->toBe(1)
        ->and(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Queued);
});

it('a retried/redelivered job does not resend or open a second sms_logs row once the first attempt already sent', function (): void {
    $provider = new CountingSmsProviderFake;

    $job = new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Reminder24h,
    ));

    // First run: reaches the provider and settles the log to Sent.
    $job->handle($provider);

    // Simulated retry/redelivery: Horizon reuses the same serialized job instance
    // and calls handle() again — must not hit the provider or open a new row.
    $job->handle($provider);

    expect($provider->calls)->toBe(1)
        ->and(SmsLog::withoutGlobalScopes()->count())->toBe(1);

    expect(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Sent);
});

it('a retry after a failed send is still allowed to resend — Failed is not treated as terminal', function (): void {
    $provider = new class implements SmsProviderInterface
    {
        public int $calls = 0;

        public function send(string $phone, string $body): SmsResponse
        {
            $this->calls++;

            return $this->calls === 1
                ? SmsResponse::failure('Netgsm error code 40')
                : SmsResponse::success('ok');
        }
    };

    $job = new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Reminder24h,
    ));

    $job->handle($provider); // fails
    $job->handle($provider); // legitimate retry after a genuine failure — must still attempt

    expect($provider->calls)->toBe(2)
        ->and(SmsLog::withoutGlobalScopes()->count())->toBe(1);

    expect(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Sent);
});

// ---------------------------------------------------------------------------
// failed() — Horizon calls this once retries (supervisor-sms tries:3) are exhausted
// ---------------------------------------------------------------------------

it('failed() settles a still-Queued log row to Failed with the exception message', function (): void {
    $job = new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Reminder24h,
    ));

    $job->failed(new RuntimeException('Netgsm connection timed out'));

    $log = SmsLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->error)->toBe('Netgsm connection timed out');
});

it('failed() does not pull an already-Sent log row back to Failed', function (): void {
    $provider = new CountingSmsProviderFake;

    $job = new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Reminder24h,
    ));

    // The send itself succeeded; imagine Horizon still calls failed() for some unrelated
    // reason (e.g. a middleware exception after handle() returned) — the settled Sent
    // row must never be downgraded.
    $job->handle($provider);
    $job->failed(new RuntimeException('should not matter'));

    $log = SmsLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe(SmsStatus::Sent)
        ->and($log->error)->toBeNull();
});
