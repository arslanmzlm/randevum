<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function srTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create an appointment due for the 24h reminder window relative to $now.
 * Default status = Confirmed, reminder flags = false.
 *
 * @param  array<string, mixed>  $overrides
 */
function appointmentDueIn24h(Carbon $now, array $overrides = []): Appointment
{
    $startsAt = $now->copy()->addHours(24);

    return Appointment::factory()->create(array_merge([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => false,
        'reminder_1h_sent' => false,
    ], $overrides));
}

/**
 * Create an appointment due for the 1h reminder window relative to $now.
 *
 * @param  array<string, mixed>  $overrides
 */
function appointmentDueIn1h(Carbon $now, array $overrides = []): Appointment
{
    $startsAt = $now->copy()->addHour();

    return Appointment::factory()->create(array_merge([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => false,
        'reminder_1h_sent' => false,
    ], $overrides));
}

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

// ---------------------------------------------------------------------------
// 24h window — Confirmed appointment dispatches Reminder24h
// ---------------------------------------------------------------------------

it('dispatches Reminder24h for a Confirmed appointment in the 24h window and sets the flag', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $appointment = appointmentDueIn24h($now);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class);

    $appointment->refresh();
    expect($appointment->reminder_24h_sent)->toBeTrue()
        ->and($appointment->reminder_1h_sent)->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 1h window — Rescheduled appointment dispatches Reminder1h
// ---------------------------------------------------------------------------

it('dispatches Reminder1h for a Rescheduled appointment in the 1h window and sets the flag', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $appointment = appointmentDueIn1h($now, ['status' => AppointmentStatus::Rescheduled]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class);

    $appointment->refresh();
    expect($appointment->reminder_1h_sent)->toBeTrue()
        ->and($appointment->reminder_24h_sent)->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Outside window — no dispatch
// ---------------------------------------------------------------------------

it('does not dispatch for an appointment outside the 24h ±5min window', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    // starts_at is 24h + 10 min away — well outside ±5 min half-window.
    $startsAt = $now->copy()->addHours(24)->addMinutes(10);

    Appointment::factory()->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => false,
        'reminder_1h_sent' => false,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

it('does not dispatch for an appointment outside the 1h ±5min window', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    // starts_at is 1h + 10 min away — well outside ±5 min half-window.
    $startsAt = $now->copy()->addHour()->addMinutes(10);

    Appointment::factory()->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => false,
        'reminder_1h_sent' => false,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Status filter — only Confirmed / Rescheduled are eligible
// ---------------------------------------------------------------------------

it('does not dispatch for non-Confirmed/Rescheduled statuses inside the 24h window', function (AppointmentStatus $status): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    appointmentDueIn24h($now, ['status' => $status]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
})->with([
    'Pending' => AppointmentStatus::Pending,
    'Arrived' => AppointmentStatus::Arrived,
    'Cancelled' => AppointmentStatus::Cancelled,
    'Completed' => AppointmentStatus::Completed,
    'NoShow' => AppointmentStatus::NoShow,
]);

// ---------------------------------------------------------------------------
// Flag guard — already-flagged appointments are skipped
// ---------------------------------------------------------------------------

it('skips a 24h appointment whose reminder_24h_sent flag is already true', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    appointmentDueIn24h($now, ['reminder_24h_sent' => true]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

it('skips a 1h appointment whose reminder_1h_sent flag is already true', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    appointmentDueIn1h($now, ['reminder_1h_sent' => true]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Second idempotency guard — existing Sent sms_logs row blocks dispatch
// ---------------------------------------------------------------------------

it('skips dispatch when an sms_logs Sent row exists for that appointment+type even if the flag is false', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $appointment = appointmentDueIn24h($now, ['reminder_24h_sent' => false]);

    // Simulate a Sent log that exists despite the flag not persisting.
    SmsLog::withoutGlobalScopes()->create([
        'clinic_id' => $appointment->clinic_id,
        'patient_id' => $appointment->patient_id,
        'phone' => '+905321112233',
        'type' => SmsType::Reminder24h,
        'loggable_type' => 'appointment',
        'loggable_id' => $appointment->id,
        'body' => 'previous send',
        'status' => SmsStatus::Sent,
        'scheduled_at' => now(),
        'sent_at' => now(),
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    // The flag gets repaired even when the second guard fires.
    $appointment->refresh();
    expect($appointment->reminder_24h_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Null phone — gate logs Skipped, no crash, flag is set
// ---------------------------------------------------------------------------

it('handles null patient phone cleanly — writes Skipped sms_logs and sets the flag', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => null]);
    $doctor = Doctor::factory()->for($clinic)->create();
    $appointment = appointmentDueIn24h($now, [
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('no phone');

    $appointment->refresh();
    expect($appointment->reminder_24h_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Idempotency — re-running immediately dispatches nothing new
// ---------------------------------------------------------------------------

it('running the command twice dispatches only once (idempotent)', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    appointmentDueIn24h($now);

    $this->artisan('sms:send-reminders')->assertSuccessful();
    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class, 1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Clinic toggle disabled → Skipped log, flag still set
// ---------------------------------------------------------------------------

it('when the clinic disables reminder_24h the gate logs Skipped and the flag is still set', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->for($clinic)->create();
    $appointment = appointmentDueIn24h($now, [
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    ClinicSmsSetting::factory()->forType(SmsType::Reminder24h)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('disabled by clinic');

    $appointment->refresh();
    expect($appointment->reminder_24h_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('two clinics each get exactly one reminder and sms_logs clinic_id matches each appointment', function (): void {
    // Use real send path (NullSmsProvider + sync queue) so sms_logs rows are written.
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);

    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinicA = Clinic::factory()->create();
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'phone' => '+905321112233']);
    $doctorA = Doctor::factory()->for($clinicA)->create();
    $appointmentA = appointmentDueIn24h($now, [
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'doctor_id' => $doctorA->id,
    ]);

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'phone' => '+905329999999']);
    $doctorB = Doctor::factory()->for($clinicB)->create();
    $appointmentB = appointmentDueIn24h($now, [
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    $logA = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointmentA->id)
        ->where('status', SmsStatus::Sent)
        ->sole();

    $logB = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointmentB->id)
        ->where('status', SmsStatus::Sent)
        ->sole();

    expect($logA->clinic_id)->toBe($clinicA->id)
        ->and($logB->clinic_id)->toBe($clinicB->id)
        ->and($logA->clinic_id)->not->toBe($logB->clinic_id);

    $appointmentA->refresh();
    $appointmentB->refresh();
    expect($appointmentA->reminder_24h_sent)->toBeTrue()
        ->and($appointmentB->reminder_24h_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Manual sendReminder endpoint — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from POST /appointments/{appointment}/send-reminder', function (): void {
    $appointment = Appointment::factory()->create(['status' => AppointmentStatus::Confirmed]);

    $this->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect(route('login'));
});

it('owner can POST send-reminder and the job is dispatched', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    srTestRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('manager can POST send-reminder', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    srTestRole($manager, 'manager', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($manager)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('receptionist can POST send-reminder', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    srTestRole($receptionist, 'receptionist', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($receptionist)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('doctor role without appointments.viewAll gets 403 on another doctor appointment', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $actingDoctorUser = User::factory()->create();
    srTestRole($actingDoctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $actingDoctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $otherDoctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($actingDoctorUser)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('doctor role (no sendReminder permission) gets 403 even on own appointment', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    srTestRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    // The appointment belongs to the acting doctor — but the doctor role doesn't
    // hold appointments.sendReminder, so ownership doesn't help.
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($doctorUser)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('user without appointments.sendReminder permission gets 403', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    srTestRole($assistant, 'assistant', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($assistant)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// Cross-tenant isolation — endpoint must 404 for another clinic's appointment
// ---------------------------------------------------------------------------

it('user with appointments.sendReminder in clinic A gets 404 posting send-reminder for clinic B appointment', function (): void {
    Queue::fake();

    $clinicA = Clinic::factory()->create();
    $owner = User::factory()->create();
    srTestRole($owner, 'owner', $clinicA->id);

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $appointmentB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Clinic A owner POSTs against clinic B's appointment — ClinicScope hides it → 404.
    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointmentB))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// Manual send does NOT read or set reminder flags
// ---------------------------------------------------------------------------

it('manual send-reminder does not set reminder_24h_sent flag', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    srTestRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => false,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    $appointment->refresh();
    // Manual action must NOT set the auto flag.
    expect($appointment->reminder_24h_sent)->toBeFalse();
    Queue::assertPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// Custom SMS template (1.33) — AppointmentReminderService integrates the renderer
// ---------------------------------------------------------------------------

it('a due 24h reminder with a custom Reminder24h template sends the custom body with :patient substituted', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'phone' => '+905321112233']);
    $doctor = Doctor::factory()->for($clinic)->create();

    ClinicSmsSetting::factory()
        ->forType(SmsType::Reminder24h)
        ->withTemplate('Sayın :patient, yarınki randevunuzu hatırlatırız.')
        ->create(['clinic_id' => $clinic->id]);

    $appointment = appointmentDueIn24h($now, [
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->sole();

    expect($log->body)->toBe('Sayın Ayşe Yılmaz, yarınki randevunuzu hatırlatırız.');

    Carbon::setTestNow();
});

it('a due 24h reminder without a custom template falls back to the shared sms.reminder.body lang default', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);
    $now = Carbon::parse('2026-06-20 10:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321112233']);
    $doctor = Doctor::factory()->for($clinic)->create();

    $appointment = appointmentDueIn24h($now, [
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->artisan('sms:send-reminders')->assertSuccessful();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->sole();

    expect($log->body)->toContain('randevunuzu hatırlatırız'); // lang/tr/sms.php reminder default phrasing

    Carbon::setTestNow();
});

it('the manual send-reminder path (Reminder24h) also renders the custom template', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);

    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $owner = User::factory()->create();
    srTestRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'phone' => '+905321234567']);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    ClinicSmsSetting::factory()
        ->forType(SmsType::Reminder24h)
        ->withTemplate('Elden yazılmış hatırlatma, sayın :patient.')
        ->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->sole();

    expect($log->body)->toBe('Elden yazılmış hatırlatma, sayın Ayşe Yılmaz.');
});

it('manual send-reminder can resend even when reminder_24h_sent is already true', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    srTestRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Confirmed,
        'reminder_24h_sent' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    // Staff deliberately resends — must still dispatch.
    Queue::assertPushed(SendSmsJob::class);
});
