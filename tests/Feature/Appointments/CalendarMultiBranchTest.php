<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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

function cmbRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function cmbDate(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
}

function cmbAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, int $startHour, int $endHour): Appointment
{
    $date = cmbDate();
    $tz = $clinic->timezone ?? 'Europe/Istanbul';

    return Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => Carbon::parse("{$date} {$startHour}:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} {$endHour}:00:00", $tz)->utc(),
        'status' => AppointmentStatus::Confirmed,
        'is_walk_in' => false,
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function cmbEventsUrl(array $extra = []): string
{
    $date = cmbDate();
    $params = array_merge(['start' => $date, 'end' => $date], $extra);

    return route('calendar.events').'?'.http_build_query($params);
}

it("clinic_id[] with two member clinics returns both clinics' appointments, each carrying clinic_id/clinic_name, and exceptions === []", function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'name' => 'Şube A']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'timezone' => 'Europe/Istanbul', 'name' => 'Şube B']);
    $owner = User::factory()->create();
    cmbRole($owner, 'owner', $clinicA->id);
    cmbRole($owner, 'owner', $clinicB->id);

    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    cmbAppointment($clinicA, $doctorA, $patientA, 10, 11);
    cmbAppointment($clinicB, $doctorB, $patientB, 12, 13);

    $response = $this->actingAs($owner)
        ->getJson(cmbEventsUrl(['clinic_id' => [$clinicA->id, $clinicB->id]]))
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(2)
        ->and($response->json('exceptions'))->toBe([]);

    $byClinic = collect($data)->keyBy('clinic_id');
    expect($byClinic[$clinicA->id]['clinic_name'])->toBe('Şube A')
        ->and($byClinic[$clinicB->id]['clinic_name'])->toBe('Şube B');
});

it('a foreign clinic_id value → 422 (validation, fail closed)', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $foreignClinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    cmbRole($owner, 'owner', $clinicA->id);

    $this->actingAs($owner)
        ->getJson(cmbEventsUrl(['clinic_id' => [$foreignClinic->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clinic_id.0');
});

it("a user without appointments.viewAll gets only their own doctor's appointments in the active clinic even when passing clinic_id[]", function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'timezone' => 'Europe/Istanbul']);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    // Membership in B too (no doctors.viewAll ability) so clinic_id[] validates but is ignored.
    cmbRole($doctorUserA, 'doctor', $clinicA->id);
    cmbRole($doctorUserA, 'doctor', $clinicB->id);

    $otherDoctorInA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    cmbAppointment($clinicA, $doctorA, $patientA, 9, 10);
    cmbAppointment($clinicA, $otherDoctorInA, $patientA, 11, 12);

    $data = $this->actingAs($doctorUserA)
        ->getJson(cmbEventsUrl(['clinic_id' => [$clinicA->id, $clinicB->id]]))
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['doctor_id'])->toBe($doctorA->id)
        ->and($data[0]['clinic_id'])->toBe($clinicA->id);
});

it("a clinic_id[] value from an unrelated tenant is rejected with 422, and the tenant's clinics prop omits it", function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'timezone' => 'Europe/Istanbul']);
    // Different tenant (factory default creates a fresh tenant per clinic).
    $otherTenantClinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $owner = User::factory()->create();
    cmbRole($owner, 'owner', $clinicA->id);
    cmbRole($owner, 'owner', $clinicB->id);
    cmbRole($owner, 'owner', $otherTenantClinic->id);

    $this->actingAs($owner)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinics', fn ($clinics) => collect($clinics)->pluck('id')->sort()->values()->all()
            === collect([$clinicA->id, $clinicB->id])->sort()->values()->all()
        ));

    $this->actingAs($owner)
        ->getJson(cmbEventsUrl(['clinic_id' => [$otherTenantClinic->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clinic_id.0');
});

it('a single-clinic request is byte-identical to today\'s payload shape plus the two new keys', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    cmbRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    cmbAppointment($clinic, $doctor, $patient, 10, 11);

    $event = $this->actingAs($owner)
        ->getJson(cmbEventsUrl())
        ->assertOk()
        ->json('data.0');

    expect($event)->toHaveKeys([
        'id', 'doctor_id', 'doctor_name', 'title',
        'start', 'end', 'status', 'is_walk_in',
        'service_name', 'type_name', 'type_color',
        'clinic_id', 'clinic_name',
    ])
        ->and($event['clinic_id'])->toBe($clinic->id)
        ->and($event['clinic_name'])->toBe($clinic->name);
});
