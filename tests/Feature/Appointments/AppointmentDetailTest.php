<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Core\Services\StatusLogService;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function adRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic + a doctor (user + profile) + patient, ready to hold an appointment.
 *
 * @return array{clinic: Clinic, doctorUser: User, doctor: Doctor, patient: Patient}
 */
function adDoctorSetup(): array
{
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'doctorUser', 'doctor', 'patient');
}

function adAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ], $overrides));
}

/**
 * Insert an SmsLog row tied to a polymorphic loggable.
 */
function adSmsLog(Clinic $clinic, string $loggableType, int $loggableId, array $overrides = []): SmsLog
{
    return SmsLog::create(array_merge([
        'clinic_id' => $clinic->id,
        'patient_id' => null,
        'phone' => '+905301234567',
        'type' => SmsType::AppointmentCreated->value,
        'loggable_type' => $loggableType,
        'loggable_id' => $loggableId,
        'body' => 'Test SMS body',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'scheduled_at' => null,
        'sent_at' => now(),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// GET /appointments/{appointment} — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/{appointment}', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $appointment = adAppointment($clinic, $doctor, $patient);

    $this->get(route('appointments.show', $appointment))
        ->assertRedirect(route('login'));
});

it('a user without appointments.viewAny gets 403', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $appointment = adAppointment($clinic, $doctor, $patient);

    $noRole = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    $this->actingAs($noRole)
        ->get(route('appointments.show', $appointment))
        ->assertForbidden();
});

it('owner can view any appointment and the Show component renders with the summary props', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/Show')
            ->where('appointment.id', $appointment->id)
            ->where('appointment.patient_id', $patient->id)
            ->where('appointment.doctor_id', $doctor->id)
            ->where('appointment.status', 'confirmed')
            ->has('statusLogs')
            ->has('treatment')
            ->has('ownDoctorId')
        );
});

it('a doctor without appointments.viewAll gets 403 on another doctor\'s appointment', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'patient' => $patient] = adDoctorSetup();
    adRole($doctorUser, 'doctor', $clinic->id);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $appointment = adAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.show', $appointment))
        ->assertForbidden();
});

it('a doctor without appointments.viewAll can view their own appointment (200)', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    adRole($doctorUser, 'doctor', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appointment.id', $appointment->id));
});

// ---------------------------------------------------------------------------
// statusLogs — ordering + actor mapping
// ---------------------------------------------------------------------------

it('statusLogs returns rows in transitioned_at order with the actor name, or null for a system transition', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::NoShow]);

    app(StatusLogService::class)->record($appointment, 'confirmed', 'arrived', $owner);
    app(StatusLogService::class)->record($appointment, 'arrived', 'no_show', null, 'auto');

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('statusLogs', function ($logs) use ($owner) {
                $logs = collect($logs)->all();
                expect($logs)->toHaveCount(2)
                    ->and($logs[0]['from_status'])->toBe('confirmed')
                    ->and($logs[0]['to_status'])->toBe('arrived')
                    ->and($logs[0]['by_user_name'])->toBe($owner->name)
                    ->and($logs[1]['from_status'])->toBe('arrived')
                    ->and($logs[1]['to_status'])->toBe('no_show')
                    ->and($logs[1]['by_user_name'])->toBeNull()
                    ->and($logs[1]['reason'])->toBe('auto');

                return true;
            })
        );
});

// ---------------------------------------------------------------------------
// treatment — linked-treatment summary
// ---------------------------------------------------------------------------

it('treatment prop is null when the appointment has no treatment', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('treatment', null));
});

it('treatment prop is populated when one exists and the user may view it', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);
    $treatment = Treatment::factory()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatment.id', $treatment->id)
            ->where('treatment.status', 'draft')
            ->has('treatment.complaint')
            ->has('treatment.diagnosis')
            ->has('treatment.total_amount')
        );
});

it('treatment prop is null for a doctor who owns the appointment but not the mismatched treatment', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $ownDoctor, 'patient' => $patient] = adDoctorSetup();
    adRole($doctorUser, 'doctor', $clinic->id);

    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = adAppointment($clinic, $ownDoctor, $patient);
    // Deliberately mismatched: the treatment belongs to a different doctor than the
    // appointment/viewer, so treatments.viewAll-less ownership fails on the treatment side.
    Treatment::factory()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $otherDoctor->id,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('treatment', null));
});

// ---------------------------------------------------------------------------
// smsLogs — gated on smsLogs.viewAny, filtered by the morph link
// ---------------------------------------------------------------------------

it('smsLogs is present for a user with smsLogs.viewAny and absent otherwise', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);
    adRole($doctorUser, 'doctor', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);
    adSmsLog($clinic, 'appointment', $appointment->id);

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('smsLogs'));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($doctorUser)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('smsLogs'));
});

it('smsLogs contains only logs whose loggable matches this appointment', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $appointment = adAppointment($clinic, $doctor, $patient);
    $otherAppointment = adAppointment($clinic, $doctor, $patient);

    $ownLog = adSmsLog($clinic, 'appointment', $appointment->id, ['phone' => '+905301111111']);
    adSmsLog($clinic, 'appointment', $otherAppointment->id, ['phone' => '+905302222222']);

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs', fn ($logs) => count($logs) === 1 && $logs[0]['id'] === $ownLog->id)
        );
});

// ---------------------------------------------------------------------------
// Route ordering — /appointments/create must still resolve to create, not show
// ---------------------------------------------------------------------------

it('GET /appointments/create still resolves to the create page, not the show route', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointments/Create'));
});

// ---------------------------------------------------------------------------
// Soft-deleted patient/doctor — the appointment row outlives them
// ---------------------------------------------------------------------------

it('does not 500 and still shows names when the appointment\'s patient and doctor are soft-deleted', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = adDoctorSetup();
    $owner = User::factory()->create();
    adRole($owner, 'owner', $clinic->id);

    // starts_at in the past: keeps this appointment out of the "upcoming appointments" header
    // widget's own soft-delete gap (a separate, out-of-scope bug in AppointmentService::upcomingFor),
    // so this test isolates the /appointments/{id} fix under review.
    $appointment = adAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
    ]);

    $patient->delete();
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('appointments.show', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('appointment.patient_name', trim($patient->first_name.' '.$patient->last_name))
            ->where('appointment.doctor_name', $doctor->display_name)
        );
});
