<?php

use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\FollowUpType;
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
 * Build a clinic with owner, a doctor, and a patient — no follow-up by default.
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

/**
 * An open follow-up due on $dueDate, optionally linked to a case.
 */
function fuwFollowUp(Clinic $clinic, Patient $patient, string $dueDate, ?int $caseId = null, array $overrides = []): FollowUp
{
    return FollowUp::factory()->open()->create(array_merge([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'case_id' => $caseId,
        'due_date' => $dueDate,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Authorization — who sees follow-ups and who gets an empty array
// ---------------------------------------------------------------------------

it('owner sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);
    fuwFollowUp($clinic, $patient, fuwIstanbulToday(), $case->id);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('manager sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fuwSetup();
    $manager = User::factory()->create();
    fuwRole($manager, 'manager', $clinic->id);

    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('receptionist sees due follow-ups in the followUps Inertia prop', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fuwSetup();
    $receptionist = User::factory()->create();
    fuwRole($receptionist, 'receptionist', $clinic->id);

    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($receptionist)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('doctor (lacks followUps.view) gets followUps = [] in the Inertia prop', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fuwSetup();

    $doctorUser = User::factory()->create();
    fuwRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

it('assistant (lacks followUps.view) gets followUps = [] in the Inertia prop', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fuwSetup();
    $assistant = User::factory()->create();
    fuwRole($assistant, 'assistant', $clinic->id);

    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

// ---------------------------------------------------------------------------
// followUpTypes prop — gated on followUps.create
// ---------------------------------------------------------------------------

it('owner (has followUps.create) gets active types in the followUpTypes prop', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = fuwSetup();
    FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Kontrol']);
    FollowUpType::factory()->inactive()->create(['clinic_id' => $clinic->id, 'name' => 'Pasif']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUpTypes', 1)
            ->where('followUpTypes.0.name', 'Kontrol'));
});

it('assistant (lacks followUps.create) gets followUpTypes = []', function (): void {
    ['clinic' => $clinic] = fuwSetup();
    $assistant = User::factory()->create();
    fuwRole($assistant, 'assistant', $clinic->id);

    FollowUpType::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUpTypes', []));
});

// ---------------------------------------------------------------------------
// Filtering — which follow-ups appear in the widget
// ---------------------------------------------------------------------------

it('includes a follow-up with due_date equal to clinic-local today', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('includes overdue follow-ups (due_date in the past)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, today()->subDays(3)->format('Y-m-d'));

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUps', 1));
});

it('excludes a follow-up with a future due_date', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, today()->addDays(3)->format('Y-m-d'));

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

it('excludes a done follow-up even when its due_date is today or overdue', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, fuwIstanbulToday(), null, ['status' => 'done', 'completed_at' => now()]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});

// ---------------------------------------------------------------------------
// is_overdue — strictly less than clinic-local today; today itself is NOT overdue
// ---------------------------------------------------------------------------

it('sets is_overdue=false for a follow-up due on clinic-local today', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.is_overdue', false)
        );
});

it('sets is_overdue=true for a follow-up due two days ago', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, today()->subDays(2)->format('Y-m-d'));

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
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();

    $oldest = fuwFollowUp($clinic, $patient, today()->subDays(5)->format('Y-m-d'));
    $newest = fuwFollowUp($clinic, $patient, today()->subDays(2)->format('Y-m-d'));

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 2)
            ->where('followUps.0.id', $oldest->id)
            ->where('followUps.1.id', $newest->id)
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
    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Kontrol araması']);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $followUp = fuwFollowUp($clinic, $patient, today()->subDays(2)->format('Y-m-d'), $case->id, [
        'follow_up_type_id' => $type->id,
        'note' => 'Check progress',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->has('followUps.0.id')
            ->has('followUps.0.case_id')
            ->has('followUps.0.patient')
            ->has('followUps.0.patient.id')
            ->has('followUps.0.patient.full_name')
            ->has('followUps.0.patient.phone')
            ->has('followUps.0.doctor')
            ->has('followUps.0.doctor.id')
            ->has('followUps.0.doctor.display_name')
            ->has('followUps.0.type')
            ->has('followUps.0.type.id')
            ->has('followUps.0.type.name')
            ->has('followUps.0.due_date')
            ->has('followUps.0.note')
            ->has('followUps.0.is_overdue')
            ->where('followUps.0.id', $followUp->id)
            ->where('followUps.0.case_id', $case->id)
            ->where('followUps.0.patient.id', $patient->id)
            ->where('followUps.0.patient.full_name', 'Ayşe Demir')
            ->where('followUps.0.doctor.id', $doctor->id)
            ->where('followUps.0.type.name', 'Kontrol araması')
            ->where('followUps.0.note', 'Check progress')
            ->where('followUps.0.due_date', today()->subDays(2)->format('Y-m-d'))
        );
});

it('id, case_id, patient.id, and doctor.id are integers (not strings)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);
    fuwFollowUp($clinic, $patient, fuwIstanbulToday(), $case->id);

    // Postgres returns bigint as string via PDO — the service must cast to int.
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('followUps.0.id', fn ($v) => is_int($v))
            ->where('followUps.0.case_id', fn ($v) => is_int($v))
            ->where('followUps.0.doctor.id', fn ($v) => is_int($v))
            ->where('followUps.0.patient.id', fn ($v) => is_int($v))
        );
});

it('case_id, doctor, and type are null for a case-less follow-up', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, fuwIstanbulToday());

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.case_id', null)
            ->where('followUps.0.doctor', null)
        );
});

it('note is null when the follow-up has no note', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fuwSetup();
    fuwFollowUp($clinic, $patient, fuwIstanbulToday(), null, ['note' => null]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.note', null)
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user cannot see clinic B follow-ups in the followUps prop', function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    fuwRole($ownerA, 'owner', $clinicA->id);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    // Clinic A follow-up — must appear for ownerA.
    $followUpA = fuwFollowUp($clinicA, $patientA, fuwIstanbulToday());

    // Clinic B follow-ups — must NOT bleed through to clinic A.
    fuwFollowUp($clinicB, $patientB, fuwIstanbulToday());
    fuwFollowUp($clinicB, $patientB, today()->subDays(2)->format('Y-m-d'));

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.id', $followUpA->id)
        );
});

it('followUps carry doctor.is_deleted for a soft-deleted case doctor and keep the name', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fuwSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    fuwFollowUp($clinic, $patient, fuwIstanbulToday(), $case->id);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps.0.doctor.is_deleted', false));

    $name = $doctor->display_name;
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUps', 1)
            ->where('followUps.0.doctor.display_name', $name)
            ->where('followUps.0.doctor.is_deleted', true)
        );
});
