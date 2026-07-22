<?php

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the lang default body when no custom template row exists', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Clinic Alpha', 'locale' => 'tr_TR']);

    $body = app(SmsTemplateRendererContract::class)->resolve($clinic, SmsType::AppointmentCreated, [
        'clinic' => $clinic->name,
        'date' => '15 Ağustos 2026',
        'time' => '14:30',
        'patient' => 'Ayşe Yılmaz',
        'doctor' => 'Dr. Mehmet Demir',
    ]);

    $expected = strtr(__('sms.appointment.created.body', [], 'tr'), [
        ':clinic' => 'Clinic Alpha',
        ':date' => '15 Ağustos 2026',
        ':time' => '14:30',
    ]);

    expect($body)->toBe($expected)
        ->toContain('Clinic Alpha')
        ->toContain('15 Ağustos 2026')
        ->toContain('14:30');
});

it('a custom template wins over the lang default', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Merhaba :patient, :clinic randevunuz :date :time.')
        ->create(['clinic_id' => $clinic->id]);

    $body = app(SmsTemplateRendererContract::class)->resolve($clinic, SmsType::AppointmentCreated, [
        'clinic' => 'Clinic Alpha',
        'date' => '15 Ağustos 2026',
        'time' => '14:30',
        'patient' => 'Ayşe Yılmaz',
        'doctor' => 'Dr. Mehmet Demir',
    ]);

    expect($body)->toBe('Merhaba Ayşe Yılmaz, Clinic Alpha randevunuz 15 Ağustos 2026 14:30.');
});

it('an empty-string custom template row falls back to the lang default, not a blank body', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCancelled)
        ->withTemplate('')
        ->create(['clinic_id' => $clinic->id]);

    $body = app(SmsTemplateRendererContract::class)->resolve($clinic, SmsType::AppointmentCancelled, [
        'clinic' => 'Clinic Alpha',
        'date' => '15 Ağustos 2026',
        'time' => '14:30',
        'patient' => 'Ayşe Yılmaz',
        'doctor' => '',
    ]);

    $expected = strtr(__('sms.appointment.cancelled.body', [], 'tr'), [
        ':clinic' => 'Clinic Alpha',
        ':date' => '15 Ağustos 2026',
        ':time' => '14:30',
    ]);

    expect($body)->toBe($expected);
});

it('substitutes only the allowlisted variables present in $vars, leaving unknown tokens untouched', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate(':clinic - :date :time - Dr. :doctor - :unknown_token')
        ->create(['clinic_id' => $clinic->id]);

    $body = app(SmsTemplateRendererContract::class)->resolve($clinic, SmsType::AppointmentCreated, [
        'clinic' => 'Clinic Alpha',
        'date' => '15 Ağustos 2026',
        'time' => '14:30',
        'doctor' => 'Mehmet Demir',
    ]);

    expect($body)->toBe('Clinic Alpha - 15 Ağustos 2026 14:30 - Dr. Mehmet Demir - :unknown_token');
});

it('both Reminder24h and Reminder1h fall back to the shared sms.reminder.body lang key', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    $renderer = app(SmsTemplateRendererContract::class);

    $body24h = $renderer->resolve($clinic, SmsType::Reminder24h, ['clinic' => 'X', 'date' => 'D', 'time' => 'T']);
    $body1h = $renderer->resolve($clinic, SmsType::Reminder1h, ['clinic' => 'X', 'date' => 'D', 'time' => 'T']);

    $expected = strtr(__('sms.reminder.body', [], 'tr'), [':clinic' => 'X', ':date' => 'D', ':time' => 'T']);

    expect($body24h)->toBe($expected)
        ->and($body1h)->toBe($expected)
        ->and($body24h)->toBe($body1h);
});

it('Reminder24h and Reminder1h render their OWN independently-stored custom templates', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()->forType(SmsType::Reminder24h)->withTemplate('24 saatlik özel metin')->create(['clinic_id' => $clinic->id]);
    ClinicSmsSetting::factory()->forType(SmsType::Reminder1h)->withTemplate('1 saatlik özel metin')->create(['clinic_id' => $clinic->id]);

    $renderer = app(SmsTemplateRendererContract::class);

    expect($renderer->resolve($clinic, SmsType::Reminder24h, []))->toBe('24 saatlik özel metin')
        ->and($renderer->resolve($clinic, SmsType::Reminder1h, []))->toBe('1 saatlik özel metin');
});

it("normalizes the clinic's locale ('tr_TR' → 'tr') when resolving the lang default", function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);

    $body = app(SmsTemplateRendererContract::class)->resolve($clinic, SmsType::AppointmentRescheduled, [
        'clinic' => 'X', 'date' => 'D', 'time' => 'T',
    ]);

    $expected = strtr(__('sms.appointment.rescheduled.body', [], 'tr'), [':clinic' => 'X', ':date' => 'D', ':time' => 'T']);

    expect($body)->toBe($expected);
});

it("a clinic's custom template is scoped to that clinic and does not leak to another clinic's render", function (): void {
    $clinicA = Clinic::factory()->create(['locale' => 'tr_TR']);
    $clinicB = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Clinic A özel metni')
        ->create(['clinic_id' => $clinicA->id]);

    $renderer = app(SmsTemplateRendererContract::class);

    $bodyA = $renderer->resolve($clinicA, SmsType::AppointmentCreated, []);
    $bodyB = $renderer->resolve($clinicB, SmsType::AppointmentCreated, []);

    expect($bodyA)->toBe('Clinic A özel metni')
        ->and($bodyB)->toBe(__('sms.appointment.created.body', [], 'tr'))
        ->and($bodyB)->not->toBe($bodyA);
});
