<?php

use App\Models\CaseRecord;
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

/**
 * Assign a clinic-scoped role (follow-up widget tests).
 */
function fuwRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, a doctor, and a patient — no case by default.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient}
 */
function fuwSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    fuwRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctor', 'patient');
}

/**
 * The calendar date the service considers "today" for an Istanbul-timezone clinic.
 * Use this (not `today()`) wherever the assertion depends on the clinic-local today —
 * Europe/Istanbul is UTC+3, so UTC and Istanbul can differ by one calendar day late at night.
 */
function fuwIstanbulToday(): string
{
    return Carbon::now('Europe/Istanbul')->toDateString();
}

// ---------------------------------------------------------------------------
// Authorization — who sees follow-ups and who gets an empty array
// ---------------------------------------------------------------------------

it('owner sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
        'follow_up_note' => 'Due today',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('manager sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();
    $manager = User::factory()->create();
    fuwRole($manager, 'manager', $clinic->id);

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('receptionist sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();
    $receptionist = User::factory()->create();
    fuwRole($receptionist, 'receptionist', $clinic->id);

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($receptionist)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('doctor (lacks followUps.view) gets followUps = [] in the Inertia prop', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    $doctorUser = User::factory()->create();
    fuwRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

it('assistant (lacks followUps.view) gets followUps = [] in the Inertia prop', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();
    $assistant = User::factory()->create();
    fuwRole($assistant, 'assistant', $clinic->id);

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

// ---------------------------------------------------------------------------
// Filtering — which cases appear in the widget
// ---------------------------------------------------------------------------

it('includes a case with follow_up_date equal to clinic-local today', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('includes overdue cases (follow_up_date in the past)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->subDays(3)->format('Y-m-d'),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('excludes a case with a future follow_up_date', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->addDays(3)->format('Y-m-d'),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

it('excludes a case with null follow_up_date', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

// ---------------------------------------------------------------------------
// is_overdue — strictly less than clinic-local today; today itself is NOT overdue
// ---------------------------------------------------------------------------

it('sets is_overdue=false for a case due on clinic-local today', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.is_overdue', false)
        );
});

it('sets is_overdue=true for a case due two days ago', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->subDays(2)->format('Y-m-d'),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.is_overdue', true)
        );
});

// ---------------------------------------------------------------------------
// Ordering — oldest-due first
// ---------------------------------------------------------------------------

it('returns follow-ups ordered oldest-due first', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    $oldest = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->subDays(5)->format('Y-m-d'),
    ]);

    $newest = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->subDays(2)->format('Y-m-d'),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 2)
            ->where('followUps.0.case_id', $oldest->id)
            ->where('followUps.1.case_id', $newest->id)
        );
});

// ---------------------------------------------------------------------------
// Prop contract — all required keys present with correct values
// ---------------------------------------------------------------------------

it('followUps items carry the full Inertia prop contract shape', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor] = fuwSetup();

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
    ]);

    $case = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->subDays(2)->format('Y-m-d'),
        'follow_up_note' => 'Check progress',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->has('followUps.0.case_id')
            ->has('followUps.0.patient')
            ->has('followUps.0.patient.id')
            ->has('followUps.0.patient.full_name')
            ->has('followUps.0.patient.phone')
            ->has('followUps.0.doctor')
            ->has('followUps.0.doctor.id')
            ->has('followUps.0.doctor.display_name')
            ->has('followUps.0.follow_up_date')
            ->has('followUps.0.follow_up_note')
            ->has('followUps.0.is_overdue')
            ->where('followUps.0.case_id', $case->id)
            ->where('followUps.0.patient.id', $patient->id)
            ->where('followUps.0.patient.full_name', 'Ayşe Demir')
            ->where('followUps.0.follow_up_note', 'Check progress')
            ->where('followUps.0.follow_up_date', today()->subDays(2)->format('Y-m-d'))
        );
});

it('case_id, patient.id, and doctor.id are integers (not strings)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    // Postgres returns bigint as string via PDO — the service must cast to int.
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('followUps.0.case_id', fn ($v) => is_int($v))
            ->where('followUps.0.doctor.id', fn ($v) => is_int($v))
            ->where('followUps.0.patient.id', fn ($v) => is_int($v))
        );
});

it('follow_up_note is null when the case has no note', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
        'follow_up_note' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.follow_up_note', null)
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user cannot see clinic B follow-ups in the followUps prop', function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    fuwRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $clinicB = Clinic::factory()->create();
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    // Clinic A case — must appear for ownerA
    $caseA = CaseRecord::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'doctor_id' => $doctorA->id,
        'vertical_id' => $clinicA->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);

    // Clinic B cases — must NOT bleed through to clinic A
    CaseRecord::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
        'vertical_id' => $clinicB->vertical_id,
        'follow_up_date' => fuwIstanbulToday(),
    ]);
    CaseRecord::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
        'vertical_id' => $clinicB->vertical_id,
        'follow_up_date' => today()->subDays(2)->format('Y-m-d'),
    ]);

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.case_id', $caseA->id)
        );
});
