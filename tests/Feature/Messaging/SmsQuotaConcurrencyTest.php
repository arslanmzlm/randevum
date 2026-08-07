<?php

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// The quota check locks the clinic row and re-derives usage inside the open DB transaction
// (regression for the count-then-check finding — see SmsQuotaService::withLock)
// ---------------------------------------------------------------------------

it('locks the clinic row and counts usage inside the open DB transaction', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 5, 'timezone' => 'UTC']);

    // RefreshDatabase already holds the suite at transaction level 1 — compare against this
    // captured base, not against 0.
    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        $sql = strtolower($query->sql);

        // The clinic lockForUpdate() re-read inside SmsQuotaService::withLock.
        if (str_contains($sql, 'from "clinics"') && str_contains($sql, '"id" = ?')) {
            $levels['locked_clinic'] = DB::transactionLevel();
        }

        // The usedThisMonth() count query against sms_logs.
        if (str_contains($sql, 'count(') && str_contains($sql, 'sms_logs')) {
            $levels['usage_count'] = DB::transactionLevel();
        }
    });

    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Booking confirmed',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    expect($levels['locked_clinic'] ?? null)->not->toBeNull()
        ->and($levels['locked_clinic'])->toBeGreaterThan($base)
        ->and($levels['usage_count'] ?? null)->not->toBeNull()
        ->and($levels['usage_count'])->toBeGreaterThan($base);
});

// ---------------------------------------------------------------------------
// A second dispatch against the same clinic is rejected once the first has consumed
// the only slot — the write and the recount happen atomically under the clinic lock,
// so the second call's count reflects the first's already-committed row.
// ---------------------------------------------------------------------------

it('a second dispatch against an exhausted quota is skipped once the first has consumed it', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);

    $first = app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'First reminder',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    $second = app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905309999999',
        body: 'Second reminder',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();

    Queue::assertPushed(SendSmsJob::class, 1);

    $skipped = SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('status', 'skipped')
        ->where('error', 'quota exceeded')
        ->where('body', 'Second reminder')
        ->first();

    expect($skipped)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// The queue push itself is deferred past commit, so a worker never races the
// transaction that creates the log row it depends on.
// ---------------------------------------------------------------------------

it('dispatching under quota defers the job push via afterCommit', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 5, 'timezone' => 'UTC']);

    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Booking confirmed',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job): bool {
        return $job->afterCommit === true;
    });
});
