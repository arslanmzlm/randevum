<?php

use App\Enums\InstallmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
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

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped Spatie Teams role.
 */
function pirRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + patient + a pending payment-plan installment due on $dueDate.
 *
 * @param  array<string, mixed>  $overrides  applied to the installment row
 * @return array{clinic: Clinic, patient: Patient, plan: PaymentPlan, installment: PaymentPlanInstallment}
 */
function installmentDueOn(string $dueDate, array $overrides = []): array
{
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321112233']);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'total_amount' => '100.00',
        'installment_count' => 1,
    ]);

    $installment = PaymentPlanInstallment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => $dueDate,
        'amount' => '100.00',
        'status' => InstallmentStatus::Pending,
        'reminder_7d_sent' => false,
        'reminder_1d_sent' => false,
    ], $overrides));

    return compact('clinic', 'patient', 'plan', 'installment');
}

// ---------------------------------------------------------------------------
// 7-day wave
// ---------------------------------------------------------------------------

it('dispatches the 7-day reminder for an installment due in 7 days and sets reminder_7d_sent', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installment] = installmentDueOn($now->copy()->addDays(7)->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class);

    $installment->refresh();
    expect($installment->reminder_7d_sent)->toBeTrue()
        ->and($installment->reminder_1d_sent)->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// 1-day wave
// ---------------------------------------------------------------------------

it('dispatches the 1-day reminder for an installment due tomorrow and sets reminder_1d_sent', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installment] = installmentDueOn($now->copy()->addDay()->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class);

    $installment->refresh();
    expect($installment->reminder_1d_sent)->toBeTrue()
        ->and($installment->reminder_7d_sent)->toBeFalse();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Outside either window — no dispatch
// ---------------------------------------------------------------------------

it('does not dispatch for an installment outside the 7d/1d windows', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    installmentDueOn($now->copy()->addDays(10)->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

it('does not dispatch for a Paid installment even when its due_date falls in the 7d window', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    installmentDueOn($now->copy()->addDays(7)->toDateString(), [
        'status' => InstallmentStatus::Paid,
        'paid_at' => $now,
    ]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Flag guard
// ---------------------------------------------------------------------------

it('skips a 7d-due installment whose reminder_7d_sent flag is already true', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    installmentDueOn($now->copy()->addDays(7)->toDateString(), ['reminder_7d_sent' => true]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Second idempotency guard — existing Sent sms_logs row blocks dispatch
// ---------------------------------------------------------------------------

it('skips dispatch when a Sent sms_logs row already exists for that installment+type', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installment, 'clinic' => $clinic, 'patient' => $patient] = installmentDueOn($now->copy()->addDays(7)->toDateString());

    SmsLog::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'phone' => '+905321112233',
        'type' => SmsType::InstallmentDue7d,
        'loggable_type' => 'payment_plan_installment',
        'loggable_id' => $installment->id,
        'body' => 'previous send',
        'status' => SmsStatus::Sent,
        'scheduled_at' => now(),
        'sent_at' => now(),
    ]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    $installment->refresh();
    expect($installment->reminder_7d_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Null phone — Skipped log, no crash, flag still set
// ---------------------------------------------------------------------------

it('handles a null patient phone cleanly — writes a Skipped sms_logs row and still sets the flag', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => null]);
    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'total_amount' => '100.00', 'installment_count' => 1,
    ]);
    $installment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id, 'sequence' => 1,
        'due_date' => $now->copy()->addDay()->toDateString(), 'amount' => '100.00',
    ]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('no phone');

    $installment->refresh();
    expect($installment->reminder_1d_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Idempotency — re-running immediately dispatches nothing new
// ---------------------------------------------------------------------------

it('running the command twice dispatches only once per wave (idempotent)', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    installmentDueOn($now->copy()->addDays(7)->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();
    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertPushed(SendSmsJob::class, 1);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Clinic toggle disabled → Skipped log, flag still set
// ---------------------------------------------------------------------------

it('when the clinic disables installment_due_7d the gate logs Skipped and the flag is still set', function (): void {
    Queue::fake();
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installment, 'clinic' => $clinic] = installmentDueOn($now->copy()->addDays(7)->toDateString());

    ClinicSmsSetting::factory()->forType(SmsType::InstallmentDue7d)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('disabled by clinic');

    $installment->refresh();
    expect($installment->reminder_7d_sent)->toBeTrue();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('two clinics each get exactly one 7d reminder and sms_logs clinic_id matches each installment', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);

    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installmentA, 'clinic' => $clinicA] = installmentDueOn($now->copy()->addDays(7)->toDateString());
    ['installment' => $installmentB, 'clinic' => $clinicB] = installmentDueOn($now->copy()->addDays(7)->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    $logA = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installmentA->id)
        ->where('status', SmsStatus::Sent)
        ->sole();

    $logB = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installmentB->id)
        ->where('status', SmsStatus::Sent)
        ->sole();

    expect($logA->clinic_id)->toBe($clinicA->id)
        ->and($logB->clinic_id)->toBe($clinicB->id)
        ->and($logA->clinic_id)->not->toBe($logB->clinic_id);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Custom template (1.33) integration
// ---------------------------------------------------------------------------

it('a due 7d reminder with a custom InstallmentDue7d template substitutes :patient/:amount/:date', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'phone' => '+905321112233']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::InstallmentDue7d)
        ->withTemplate('Sayın :patient, :date tarihli :amount TL tutarındaki taksitiniz yaklaşıyor.')
        ->create(['clinic_id' => $clinic->id]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'total_amount' => '250.00', 'installment_count' => 1,
    ]);
    $dueDate = $now->copy()->addDays(7);
    $installment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id, 'sequence' => 1,
        'due_date' => $dueDate->toDateString(), 'amount' => '250.00',
    ]);

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->sole();

    $expectedDate = $dueDate->locale('tr')->translatedFormat('d F Y');
    expect($log->body)->toBe("Sayın Ayşe Yılmaz, {$expectedDate} tarihli 250.00 TL tutarındaki taksitiniz yaklaşıyor.");

    Carbon::setTestNow();
});

it('a due reminder without a custom template falls back to the shared sms.installment.due.body lang default', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);
    $now = Carbon::parse('2026-06-20 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    ['installment' => $installment] = installmentDueOn($now->copy()->addDay()->toDateString());

    $this->artisan('sms:send-installment-reminders')->assertSuccessful();

    $log = SmsLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->sole();

    expect($log->body)->toContain('taksit'); // lang/tr/sms.php installment.due.body phrasing

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Manual "Hatırlat" endpoint
// ---------------------------------------------------------------------------

it('guest is redirected to login from POST /payment-plans/installments/{installment}/remind', function (): void {
    ['installment' => $installment] = installmentDueOn(now()->addDay()->toDateString());

    $this->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect(route('login'));
});

it('owner can POST the manual remind endpoint and the job is dispatched', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'installment' => $installment] = installmentDueOn(now()->addDays(20)->toDateString());

    $owner = User::factory()->create();
    pirRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('manual remind does not read or set the reminder_7d_sent/reminder_1d_sent flags', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'installment' => $installment] = installmentDueOn(now()->addDays(20)->toDateString());

    $owner = User::factory()->create();
    pirRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect();

    $installment->refresh();
    expect($installment->reminder_7d_sent)->toBeFalse()
        ->and($installment->reminder_1d_sent)->toBeFalse();
});

it('manual remind can resend even when reminder_1d_sent is already true', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'installment' => $installment] = installmentDueOn(now()->addDays(20)->toDateString(), [
        'reminder_1d_sent' => true,
    ]);

    $owner = User::factory()->create();
    pirRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('receptionist can POST the manual remind endpoint (paymentPlans.sendReminder)', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'installment' => $installment] = installmentDueOn(now()->addDays(20)->toDateString());

    $receptionist = User::factory()->create();
    pirRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

it('doctor is denied the manual remind endpoint (lacks paymentPlans.sendReminder)', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'installment' => $installment] = installmentDueOn(now()->addDays(20)->toDateString());

    $doctorUser = User::factory()->create();
    pirRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertForbidden();

    Queue::assertNothingPushed();
});
