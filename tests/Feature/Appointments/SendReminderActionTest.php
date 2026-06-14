<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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

function srRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function srAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// POST /appointments/{appointment}/send-reminder — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from POST send-reminder', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = srAppointment($clinic, $doctor, $patient);

    $this->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect(route('login'));
});

it('assistant gets 403 (has viewAll but no appointments.sendReminder)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    srRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = srAppointment($clinic, $doctor, $patient);

    $this->actingAs($assistant)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertForbidden();
});

it('doctor gets 403 even on their own appointment (sendReminder not granted to doctor)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    srRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = srAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Behaviour — dispatches a reminder through the gate
// ---------------------------------------------------------------------------

it('receptionist can send a manual reminder; it dispatches through the gate and flashes a toast', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    srRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = srAppointment($clinic, $doctor, $patient);

    $this->actingAs($receptionist)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect()
        ->assertSessionHas('toasts');

    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient) {
        return $job->message->type === SmsType::Reminder24h
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id;
    });
});

it('owner can send a manual reminder', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    srRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = srAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 sending a reminder for a clinic B appointment, and nothing is dispatched', function (): void {
    Queue::fake();

    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    srRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'phone' => '+905321234567']);
    $appointmentB = srAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->post(route('appointments.send-reminder', $appointmentB))
        ->assertNotFound();

    Queue::assertNothingPushed();
});
