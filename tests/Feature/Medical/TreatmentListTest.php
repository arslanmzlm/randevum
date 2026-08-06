<?php

use App\Enums\TreatmentStatus;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\TreatmentServiceLine;
use App\Models\User;
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
function tlRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic + a doctor (user + profile) + patient, ready to hold a treatment.
 *
 * @return array{clinic: Clinic, doctorUser: User, doctor: Doctor, patient: Patient}
 */
function tlDoctorSetup(array $clinicOverrides = []): array
{
    $clinic = Clinic::factory()->create($clinicOverrides);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'doctorUser', 'doctor', 'patient');
}

function tlTreatment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Treatment
{
    return Treatment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// GET /treatments — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /treatments', function (): void {
    $this->get(route('treatments.index'))
        ->assertRedirect(route('login'));
});

it('a user with no clinic role gets 403 on GET /treatments', function (): void {
    ['clinic' => $clinic] = tlDoctorSetup();

    $noRole = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    $this->actingAs($noRole)
        ->get(route('treatments.index'))
        ->assertForbidden();
});

it('owner can access GET /treatments and the Index component renders', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Index')
            ->has('treatments')
            ->has('doctors')
            ->has('services')
            ->has('query')
        );
});

// ---------------------------------------------------------------------------
// Visibility scoping — viewAll vs own doctor vs no doctor profile
// ---------------------------------------------------------------------------

it('treatments.viewAll holder sees another doctor\'s treatment', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $treatment = tlTreatment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $treatment->id)
        );
});

it('a doctor without treatments.viewAll sees only their own treatments', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    tlRole($doctorUser, 'doctor', $clinic->id);

    $ownTreatment = tlTreatment($clinic, $doctor, $patient);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    tlTreatment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $ownTreatment->id)
        );
});

it('a user with treatments.viewAny but no doctor profile and no viewAll gets an empty paginator', function (): void {
    $clinic = Clinic::factory()->create();
    $noProfileDoctor = User::factory()->create();
    tlRole($noProfileDoctor, 'doctor', $clinic->id);
    // Deliberately no Doctor::factory() row for this user.

    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    tlTreatment($clinic, $otherDoctor, $patient);

    $this->actingAs($noProfileDoctor)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('treatments.meta.total', 0));
});

it('doctors prop is empty without treatments.viewAll and populated with it', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser] = tlDoctorSetup();
    tlRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('doctors', []));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('doctors', fn ($doctors) => count($doctors) >= 1));
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('filter[status] narrows to matching treatments', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $draft = tlTreatment($clinic, $doctor, $patient, ['status' => TreatmentStatus::Draft]);
    tlTreatment($clinic, $doctor, $patient, ['status' => TreatmentStatus::Completed, 'completed_at' => now()]);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['status' => 'draft']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $draft->id)
        );
});

it('filter[doctor_id] narrows to the given doctors', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctorA, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $doctorC = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $treatmentA = tlTreatment($clinic, $doctorA, $patient);
    $treatmentB = tlTreatment($clinic, $doctorB, $patient);
    tlTreatment($clinic, $doctorC, $patient);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['doctor_id' => "{$doctorA->id},{$doctorB->id}"]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 2)
            ->where('treatments.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$treatmentA->id, $treatmentB->id])->sort()->values()->all())
        );
});

it('filter[service_id] matches a treatment\'s service line', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);
    $withService = tlTreatment($clinic, $doctor, $patient);
    TreatmentServiceLine::factory()->create(['treatment_id' => $withService->id, 'service_id' => $service->id]);

    tlTreatment($clinic, $doctor, $patient); // no service line

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['service_id' => (string) $service->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $withService->id)
        );
});

it('filter[service_id]=none matches treatments with no service line', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);
    $withService = tlTreatment($clinic, $doctor, $patient);
    TreatmentServiceLine::factory()->create(['treatment_id' => $withService->id, 'service_id' => $service->id]);

    $withoutService = tlTreatment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['service_id' => 'none']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $withoutService->id)
        );
});

it('filter[start_date]/[end_date] narrows to the clinic-local range', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $inRange = tlTreatment($clinic, $doctor, $patient, ['created_at' => '2026-02-15 09:00:00']);
    tlTreatment($clinic, $doctor, $patient, ['created_at' => '2026-04-01 09:00:00']);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['start_date' => '2026-02-01', 'end_date' => '2026-02-28']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $inRange->id)
        );
});

it('filter[search] matches the patient\'s name', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $ayse = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz']);
    $fatma = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'last_name' => 'Kaya']);

    $match = tlTreatment($clinic, $doctor, $ayse);
    tlTreatment($clinic, $doctor, $fatma);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['search' => 'Ayşe']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $match->id)
        );
});

it('query prop echoes the applied filters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('treatments.index', ['filter' => ['status' => 'draft', 'search' => 'test-term']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.status', 'draft')
            ->where('query.filter.search', 'test-term')
        );
});

// ---------------------------------------------------------------------------
// Soft-deleted patient/doctor — the treatment row outlives them
// ---------------------------------------------------------------------------

it('does not 500 and still shows names when the treatment\'s patient and doctor are soft-deleted', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = tlDoctorSetup();
    $owner = User::factory()->create();
    tlRole($owner, 'owner', $clinic->id);

    $treatment = tlTreatment($clinic, $doctor, $patient);

    $patient->delete();
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $treatment->id)
            ->where('treatments.data.0.patient.full_name', trim($patient->first_name.' '.$patient->last_name))
            ->where('treatments.data.0.doctor.display_name', $doctor->display_name)
        );
});
