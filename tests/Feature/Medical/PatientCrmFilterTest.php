<?php

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientSegment;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Tag;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
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
function pcfRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create a Completed treatment (a "visit") for the patient at the given completed_at.
 */
function pcfVisit(Clinic $clinic, Patient $patient, Doctor $doctor, string $completedAt): Treatment
{
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $detail = PodiatryTreatmentDetail::create([]);

    return Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'total_amount' => 100,
        'status' => TreatmentStatus::Completed,
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        'created_by' => null,
    ]);
}

// ---------------------------------------------------------------------------
// tags / segments Inertia props
// ---------------------------------------------------------------------------

it('index exposes the active clinic\'s tags as filter/chip options', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP', 'color' => '#FF0000']);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'VIP')
            ->where('tags.0.color', '#FF0000')
        );
});

it('index exposes saved segments with their criteria', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    PatientSegment::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Legacy',
        'criteria' => ['is_legacy' => true],
    ]);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->has('segments', 1)
            ->where('segments.0.name', 'Legacy')
            ->where('segments.0.criteria.is_legacy', true)
        );
});

it('patient resource on the list carries the patient\'s tags as colored chips', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP', 'color' => '#00FF00']);
    $patient->tags()->attach($tag);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->where('patients.data.0.tags.0.id', $tag->id)
            ->where('patients.data.0.tags.0.name', 'VIP')
            ->where('patients.data.0.tags.0.color', '#00FF00')
        );
});

it('query prop echoes the tags / last_visit_after / last_visit_before filters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => [
            'tags' => (string) $tag->id,
            'last_visit_after' => '2026-01-01',
            'last_visit_before' => '2026-06-01',
        ]]))
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.tags', [(string) $tag->id])
            ->where('query.filter.last_visit_after', '2026-01-01')
            ->where('query.filter.last_visit_before', '2026-06-01')
        );
});

it('query prop defaults tags to an empty array and last-visit dates to null when absent', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.tags', [])
            ->where('query.filter.last_visit_after', null)
            ->where('query.filter.last_visit_before', null)
        );
});

// ---------------------------------------------------------------------------
// Tag filter — OR semantics (GATE-1 OPEN-5)
// ---------------------------------------------------------------------------

it('tag filter returns patients holding ANY of the selected tags (OR)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    $tagA = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $tagB = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $tagC = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'HasA']);
    $patientA->tags()->attach($tagA);

    $patientB = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'HasB']);
    $patientB->tags()->attach($tagB);

    $patientC = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'HasC']);
    $patientC->tags()->attach($tagC);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['tags' => "{$tagA->id},{$tagB->id}"]]))
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 2));
});

it('tag filter with no matching tag returns an empty result', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);
    Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['tags' => (string) $tag->id]]))
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 0));
});

it('a foreign-clinic tag id in the filter matches nothing (tenant-safe no-op)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id]);

    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinicA->id);
    Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['tags' => (string) $tagB->id]]))
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 0));
});

// ---------------------------------------------------------------------------
// Last-visit filter — GATE-1 OPEN-3 (before includes never-visited)
// ---------------------------------------------------------------------------

it('last_visit_after returns only patients visited since that date', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $recent = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Recent']);
    pcfVisit($clinic, $recent, $doctor, '2026-06-10 10:00:00');

    $old = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Old']);
    pcfVisit($clinic, $old, $doctor, '2025-01-10 10:00:00');

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'NeverVisited']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['last_visit_after' => '2026-01-01']]))
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.first_name', 'Recent')
        );
});

it('last_visit_before INCLUDES never-visited patients (GATE-1 OPEN-3, locked behavior)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcfRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $recent = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Recent']);
    pcfVisit($clinic, $recent, $doctor, '2026-06-10 10:00:00');

    $lapsed = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Lapsed']);
    pcfVisit($clinic, $lapsed, $doctor, '2025-01-10 10:00:00');

    $neverVisited = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'NeverVisited']);

    // No `sort` param → default fallback is orderByDesc('id'); neverVisited was created last (higher id).
    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['last_visit_before' => '2026-01-01']]))
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 2)
            ->where('patients.data.0.id', $neverVisited->id)
            ->where('patients.data.1.id', $lapsed->id)
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation
// ---------------------------------------------------------------------------

it("clinic A's tag filter/segment never matches clinic B's patients", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pcfRole($ownerA, 'owner', $clinicA->id);

    $tagA = Tag::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'first_name' => 'PatientA']);
    $patientA->tags()->attach($tagA);

    // Same-named tag in clinic B, attached to a clinic B patient — must never leak into A's results.
    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id, 'name' => $tagA->name]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'first_name' => 'PatientB']);
    $patientB->tags()->attach($tagB);

    $this->actingAs($ownerA)
        ->get(route('patients.index', ['filter' => ['tags' => (string) $tagA->id]]))
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.first_name', 'PatientA')
        );
});
