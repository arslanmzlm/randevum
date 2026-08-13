<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Data\SmsResponse;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Providers\LogSmsProvider;
use App\Modules\Messaging\Providers\NullSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('binds the null provider by default', function (): void {
    config(['services.sms.provider' => 'null']);

    expect(app(SmsProviderInterface::class))->toBeInstanceOf(NullSmsProvider::class);
});

it('binds the log provider when configured', function (): void {
    config(['services.sms.provider' => 'log']);

    expect(app(SmsProviderInterface::class))->toBeInstanceOf(LogSmsProvider::class);
});

it('log provider writes the sms to the sms log channel', function (): void {
    config(['services.sms.provider' => 'log']);

    Log::shouldReceive('channel')->with('sms')->once()->andReturnSelf();
    Log::shouldReceive('info')->once();

    app(SmsProviderInterface::class)->send('+905321112233', 'Kod: 1234');
});

it('logs a failed send with the provider error and reference', function (): void {
    $provider = new class implements SmsProviderInterface
    {
        public function send(string $phone, string $body): SmsResponse
        {
            return SmsResponse::failure('Netgsm error code 40', 'job-999');
        }
    };

    (new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Otp,
    )))->handle($provider);

    $log = SmsLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe(SmsStatus::Failed)
        ->and($log->error)->toBe('Netgsm error code 40')
        ->and($log->provider_ref)->toBe('job-999')
        ->and($log->sent_at)->toBeNull();
});

it('logs a sent SMS through the null provider', function (): void {
    config(['services.sms.provider' => 'null']);

    (new SendSmsJob(new SmsMessage(
        phone: '+905321112233',
        body: 'Randevu hatırlatması',
        type: SmsType::Otp,
    )))->handle(app(SmsProviderInterface::class));

    $log = SmsLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe(SmsStatus::Sent)
        ->and($log->type)->toBe(SmsType::Otp)
        ->and($log->phone)->toBe('+905321112233')
        ->and($log->body)->toBe('Randevu hatırlatması')
        ->and($log->provider_ref)->not->toBeNull()
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->clinic_id)->toBeNull();
});
