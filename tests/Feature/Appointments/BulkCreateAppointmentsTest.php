<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\Carbon;
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
function bcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Next Monday at 10:00 clinic-local (Europe/Istanbul), offset by N weeks.
 */
function bcSlot(int $weeksFromFirst = 0): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)
        ->addWeeks($weeksFromFirst)
        ->format('Y-m-d H:i:s');
}

/**
 * @param  list<string>  $slots
 * @return list<array{starts_at: string}>
 */
function bcOccurrences(array $slots): array
{
    return array_map(fn (string $slot): array => ['starts_at' => $slot], $slots);
}

/**
 * Valid POST /appointments/bulk-create payload for an existing patient.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bcPayload(int $doctorId, int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_mode' => 'existing',
        'patient_id' => $patientId,
        'new_patient' => null,
        'doctor_id' => $doctorId,
        'service_id' => null,
        'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1), bcSlot(2)]),
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /appointments/bulk-create
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/bulk-create', function (): void {
    $this->get(route('appointments.bulk-create'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointments/bulk-create and the BulkCreate component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.bulk-create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/BulkCreate')
            ->has('doctors')
            ->has('services')
            ->has('appointmentTypes')
            ->has('ownDoctorId')
            ->where('result', null)
        );
});

it('receptionist can access GET /appointments/bulk-create', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    bcRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('appointments.bulk-create'))
        ->assertOk();
});

it('assistant gets 403 on GET /appointments/bulk-create (no appointments.create)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    bcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointments.bulk-create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /appointments/bulk-create — core booking behavior
// ---------------------------------------------------------------------------

it('books N Confirmed appointments for one existing patient under the actor clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id))
        ->assertRedirect(route('appointments.bulk-create'));

    $created = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->get();

    expect($created)->toHaveCount(3);

    foreach ($created as $appointment) {
        expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
            ->and($appointment->clinic_id)->toBe($clinic->id);

        $log = StatusLog::withoutGlobalScopes()
            ->where('loggable_type', 'appointment')
            ->where('loggable_id', $appointment->id)
            ->where('from_status', null)
            ->where('to_status', 'confirmed')
            ->first();

        expect($log)->not->toBeNull();
    }
});

it('flashes the bulk result and success toast after booking', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id))
        ->assertRedirect(route('appointments.bulk-create'))
        ->assertSessionHas('bulk_appointment_result', ['created' => 3, 'skipped' => []])
        ->assertSessionHas('toasts');
});

it('registers a new patient and books the appointments atomically', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, 0, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Toplu',
                'last_name' => 'Hasta',
                'phone' => '0532 111 22 33',
                'email' => 'toplu@example.com',
            ],
            'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1)]),
        ]))
        ->assertRedirect(route('appointments.bulk-create'));

    $patient = Patient::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('first_name', 'Toplu')
        ->where('last_name', 'Hasta')
        ->first();

    expect($patient)->not->toBeNull();

    $created = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('patient_id', $patient->id)
        ->get();

    expect($created)->toHaveCount(2);
});

it('does not leave an orphan patient when the batch fails validation before booking', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    // Malformed starts_at fails FormRequest validation before the service/transaction ever runs.
    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, 0, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Rollback',
                'last_name' => 'Test',
                'phone' => '',
                'email' => '',
            ],
            'occurrences' => [['starts_at' => 'not-a-date']],
        ]))
        ->assertSessionHasErrors('occurrences.0.starts_at');

    expect(Patient::withoutGlobalScopes()->where('first_name', 'Rollback')->exists())->toBeFalse();
});

it('skips a conflicting occurrence and books the remaining ones, reporting the skip count', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Block the second slot with an existing Confirmed appointment.
    $secondSlotUtc = Carbon::parse(bcSlot(1), 'Europe/Istanbul')->utc();
    Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $secondSlotUtc,
        'ends_at' => $secondSlotUtc->copy()->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id))
        ->assertRedirect(route('appointments.bulk-create'))
        ->assertSessionHas('bulk_appointment_result', fn (array $result): bool => $result['created'] === 2 && count($result['skipped']) === 1
        );

    $confirmedCount = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    // 2 newly booked + the 1 pre-existing blocker.
    expect($confirmedCount)->toBe(3);
});

it('assistant gets 403 on POST /appointments/bulk-create (no appointments.create)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    bcRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id))
        ->assertForbidden();

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('booking for another doctor without appointments.assignDoctor is forbidden', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    bcRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctorUser)
        ->post(route('appointments.bulk-create.store'), bcPayload($otherDoctor->id, $patient->id))
        ->assertForbidden();

    expect(Appointment::withoutGlobalScopes()->where('doctor_id', $otherDoctor->id)->exists())->toBeFalse();

    // Booking against their own doctor profile is allowed (mirrors single create).
    $this->actingAs($doctorUser)
        ->post(route('appointments.bulk-create.store'), bcPayload($ownDoctor->id, $patient->id))
        ->assertRedirect(route('appointments.bulk-create'));

    expect(Appointment::withoutGlobalScopes()->where('doctor_id', $ownDoctor->id)->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// FormRequest validation
// ---------------------------------------------------------------------------

it('rejects more than 12 occurrences with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $slots = [];
    for ($i = 0; $i < 13; $i++) {
        $slots[] = bcSlot($i);
    }

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id, [
            'occurrences' => bcOccurrences($slots),
        ]))
        ->assertSessionHasErrors('occurrences');
});

it('rejects zero occurrences with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id, [
            'occurrences' => [],
        ]))
        ->assertSessionHasErrors('occurrences');
});

it('rejects a malformed starts_at on an occurrence', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id, [
            'occurrences' => [['starts_at' => 'not-a-date']],
        ]))
        ->assertSessionHasErrors('occurrences.0.starts_at');
});

it('rejects a cross-clinic service_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $otherClinic = Clinic::factory()->create();
    $otherService = Service::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id, [
            'service_id' => $otherService->id,
        ]))
        ->assertSessionHasErrors('service_id');
});

it('rejects a cross-clinic appointment_type_id on an occurrence', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $otherClinic = Clinic::factory()->create();
    $otherType = AppointmentType::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id, [
            'occurrences' => [['starts_at' => bcSlot(0), 'appointment_type_id' => $otherType->id]],
        ]))
        ->assertSessionHasErrors('occurrences.0.appointment_type_id');
});

it('rejects a cross-clinic patient_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherClinic = Clinic::factory()->create();
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $otherPatient->id))
        ->assertSessionHasErrors('patient_id');
});

it('rejects a soft-deleted patient_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $patient->delete();

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, $patient->id))
        ->assertSessionHasErrors('patient_id');

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('rejects a cross-clinic doctor_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $otherClinic = Clinic::factory()->create();
    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $otherClinic->id, 'user_id' => $otherDoctorUser->id]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($otherDoctor->id, $patient->id))
        ->assertSessionHasErrors('doctor_id');
});

// ---------------------------------------------------------------------------
// POST /appointments/bulk-create/precheck — conflict pre-check probe
// ---------------------------------------------------------------------------

it('pre-check reports the slot that would be skipped as a conflict', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Block the second slot with an existing Confirmed appointment.
    $secondSlotUtc = Carbon::parse(bcSlot(1), 'Europe/Istanbul')->utc();
    Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $secondSlotUtc,
        'ends_at' => $secondSlotUtc->copy()->addMinutes(30),
    ]);

    $response = $this->actingAs($owner)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $doctor->id,
        'service_id' => null,
        'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1), bcSlot(2)]),
    ]);

    $response->assertOk();

    expect($response->json('conflicts'))
        ->toBe([Carbon::parse(bcSlot(1), 'Europe/Istanbul')->format('d.m.Y H:i')]);
});

it('pre-check returns no conflicts when every slot is free', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $doctor->id,
        'service_id' => null,
        'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1), bcSlot(2)]),
    ])
        ->assertOk()
        ->assertExactJson(['conflicts' => []]);
});

it('assistant gets 403 on the bulk pre-check (no appointments.create)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    bcRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($assistant)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $doctor->id,
        'occurrences' => bcOccurrences([bcSlot(0)]),
    ])->assertForbidden();
});

it('pre-checking another doctor without appointments.assignDoctor is forbidden', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    bcRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $this->actingAs($doctorUser)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $otherDoctor->id,
        'occurrences' => bcOccurrences([bcSlot(0)]),
    ])->assertForbidden();
});

it('pre-check rejects a cross-clinic doctor_id (multi-tenant isolation)', function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    bcRole($ownerA, 'owner', $clinicA->id);

    $clinicB = Clinic::factory()->create();
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($ownerA)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $doctorB->id,
        'occurrences' => bcOccurrences([bcSlot(0)]),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});

it('pre-check never surfaces another clinic\'s appointment as a conflict', function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    bcRole($ownerA, 'owner', $clinicA->id);
    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);

    // Clinic B books the same wall-clock slot against its own doctor.
    $clinicB = Clinic::factory()->create();
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $slotUtc = Carbon::parse(bcSlot(0), 'Europe/Istanbul')->utc();
    Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'starts_at' => $slotUtc,
        'ends_at' => $slotUtc->copy()->addMinutes(30),
    ]);

    // Clinic A's pre-check for the same slot on its own doctor sees no conflict.
    $this->actingAs($ownerA)->postJson(route('appointments.bulk-create.precheck'), [
        'doctor_id' => $doctorA->id,
        'occurrences' => bcOccurrences([bcSlot(0)]),
    ])
        ->assertOk()
        ->assertExactJson(['conflicts' => []]);
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic-A actor cannot book against clinic-B patient/doctor, and created rows stay under clinic A', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    bcRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    // Clinic B's doctor/patient are rejected by the clinic-scoped exists rules.
    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctorB->id, $patientA->id))
        ->assertSessionHasErrors('doctor_id');

    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctorA->id, $patientB->id))
        ->assertSessionHasErrors('patient_id');

    // A legitimate clinic-A booking stays scoped to clinic A.
    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctorA->id, $patientA->id))
        ->assertRedirect(route('appointments.bulk-create'));

    $created = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctorA->id)
        ->where('patient_id', $patientA->id)
        ->get();

    expect($created)->toHaveCount(3);
    foreach ($created as $appointment) {
        expect($appointment->clinic_id)->toBe($clinicA->id);
    }

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinicB->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Inline new patient reusing a soft-deleted patient's phone
// ---------------------------------------------------------------------------

it('offers to restore the soft-deleted patient when the bulk new patient reuses their phone', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $trashed = Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinen',
        'last_name' => 'Hasta',
        'phone' => '05312345678',
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, 0, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Toplu',
                'last_name' => 'Hasta',
                'phone' => '05312345678',
                'email' => null,
            ],
        ]))
        ->assertRedirect()
        ->assertSessionHas('restorable_patient', fn ($value) => $value['id'] === $trashed->id &&
            $value['full_name'] === 'Silinen Hasta'
        );

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
    expect(Patient::withoutGlobalScopes()->where('first_name', 'Toplu')->exists())->toBeFalse();
});

it('keeps the submitted bulk form input when it bounces back with the restore offer', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bcRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'phone' => '05312345678',
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctor->id, 0, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Toplu',
                'last_name' => 'Hasta',
                'phone' => '05312345678',
                'email' => null,
            ],
            'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1)]),
        ]))
        ->assertSessionHas('restorable_patient')
        ->assertSessionHasInput('doctor_id', $doctor->id)
        ->assertSessionHasInput('new_patient.first_name', 'Toplu')
        ->assertSessionHasInput('occurrences.0.starts_at', bcSlot(0))
        ->assertSessionHasInput('occurrences.1.starts_at', bcSlot(1));
});

it('does not offer another clinic\'s soft-deleted patient for restore in bulk booking', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    bcRole($ownerA, 'owner', $clinicA->id);

    $doctorUser = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUser->id]);

    // Same phone, but the soft-deleted row belongs to clinic B — phone uniqueness is per clinic.
    Patient::factory()->trashed()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'Baska',
        'last_name' => 'Klinik',
        'phone' => '05312345678',
    ]);

    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-create.store'), bcPayload($doctorA->id, 0, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Toplu',
                'last_name' => 'Hasta',
                'phone' => '05312345678',
                'email' => null,
            ],
            'occurrences' => bcOccurrences([bcSlot(0), bcSlot(1)]),
        ]))
        ->assertSessionMissing('restorable_patient')
        ->assertRedirect(route('appointments.bulk-create'));

    $patient = Patient::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('first_name', 'Toplu')
        ->first();

    expect($patient)->not->toBeNull();
    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('patient_id', $patient->id)
        ->count()
    )->toBe(2);
});
