<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Anamnesis;
use App\Models\AnamnesisField;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Medical\Services\AnamnesisReportService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (anamnesis tests).
 */
function anamAssignRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A vertical-agnostic clinic + patient (the vertical guard no longer exists).
 *
 * @return array{clinic: Clinic, patient: Patient, vertical: Vertical}
 */
function uaSetup(): array
{
    $vertical = Vertical::factory()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'patient', 'vertical');
}

/**
 * A clinic + patient whose vertical carries a full set of `extra` definitions
 * (one per AnamnesisFieldType, plus a required and an inactive one) for
 * `extra` round-trip / validation coverage.
 *
 * @return array{clinic: Clinic, patient: Patient, vertical: Vertical}
 */
function uaFieldsSetup(): array
{
    $vertical = Vertical::factory()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    AnamnesisField::factory()->text()->create(['vertical_id' => $vertical->id, 'key' => 'note_text', 'sort' => 10]);
    AnamnesisField::factory()->boolean()->create(['vertical_id' => $vertical->id, 'key' => 'flag_bool', 'sort' => 20]);
    AnamnesisField::factory()->select()->create(['vertical_id' => $vertical->id, 'key' => 'sel_choice', 'sort' => 30]);
    AnamnesisField::factory()->multiselect()->create(['vertical_id' => $vertical->id, 'key' => 'multi_choice', 'sort' => 40]);
    AnamnesisField::factory()->number()->create(['vertical_id' => $vertical->id, 'key' => 'num_value', 'sort' => 50]);
    AnamnesisField::factory()->date()->create(['vertical_id' => $vertical->id, 'key' => 'date_value', 'sort' => 60]);
    AnamnesisField::factory()->text()->create([
        'vertical_id' => $vertical->id,
        'key' => 'required_note',
        'sort' => 70,
        'required' => true,
    ]);
    AnamnesisField::factory()->text()->inactive()->create([
        'vertical_id' => $vertical->id,
        'key' => 'retired_note',
        'sort' => 80,
    ]);

    return compact('clinic', 'patient', 'vertical');
}

/**
 * A draft treatment (+ arrived appointment + doctor) whose Process screen exposes `anamnesis`.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, treatment: Treatment}
 */
function uaProcessSetup(): array
{
    $vertical = Vertical::factory()->podiatry()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female']);

    $startsAt = Carbon::now()->addDay();
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'patient', 'treatment');
}

// ---------------------------------------------------------------------------
// Permission seeding
// ---------------------------------------------------------------------------

it('anamnesis.update is seeded onto owner, manager, doctor, assistant, receptionist', function (): void {
    foreach (['owner', 'manager', 'doctor', 'assistant', 'receptionist'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        expect($role->permissions->pluck('name'))->toContain('anamnesis.update');
    }
});

it('anamnesis.update is NOT seeded onto the patient role', function (): void {
    $role = Role::findByName('patient', 'web');
    expect($role->permissions->pluck('name'))->not->toContain('anamnesis.update');
});

// ---------------------------------------------------------------------------
// First save / update-in-place — single `anamneses` row per patient
// ---------------------------------------------------------------------------

it('first PUT creates one Anamnesis row linked by patient_id with clinic_id = the active clinic', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    expect($setup['patient']->anamnesis)->toBeNull();

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'blood_type' => 'A+',
            'height_cm' => 170,
            'weight_kg' => 65.5,
            'hypertension' => true,
        ])
        ->assertRedirect();

    $anamnesis = $setup['patient']->fresh()->anamnesis;

    expect($anamnesis)->not->toBeNull()
        ->and($anamnesis->patient_id)->toBe($setup['patient']->id)
        ->and($anamnesis->clinic_id)->toBe($setup['clinic']->id)
        ->and($anamnesis->blood_type)->toBe('A+')
        ->and($anamnesis->height_cm)->toBe(170)
        ->and((float) $anamnesis->weight_kg)->toBe(65.5)
        ->and($anamnesis->hypertension)->toBeTrue();
});

it('a second PUT updates the same row rather than creating a new one', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();

    $firstId = $setup['patient']->fresh()->anamnesis->id;

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'B-'])
        ->assertRedirect();

    $fresh = $setup['patient']->fresh();

    expect($fresh->anamnesis->id)->toBe($firstId)
        ->and($fresh->anamnesis->blood_type)->toBe('B-')
        ->and(Anamnesis::count())->toBe(1);
});

it('the widened core round-trips: bleeding_disorder vs blood_thinners independent, infectious_disease_note, physician fields', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'bleeding_disorder' => true,
            'blood_thinners' => false,
            'infectious_disease' => true,
            'infectious_disease_note' => 'Hepatit B',
            'physician_name' => 'Dr. Mehmet Öz',
            'physician_phone' => '0212 555 00 00',
        ])
        ->assertRedirect();

    $anamnesis = $setup['patient']->fresh()->anamnesis;

    expect($anamnesis->bleeding_disorder)->toBeTrue()
        ->and($anamnesis->blood_thinners)->toBeFalse()
        ->and($anamnesis->infectious_disease)->toBeTrue()
        ->and($anamnesis->infectious_disease_note)->toBe('Hepatit B')
        ->and($anamnesis->physician_name)->toBe('Dr. Mehmet Öz')
        ->and($anamnesis->physician_phone)->toBe('0212 555 00 00');
});

// ---------------------------------------------------------------------------
// Authorization — doctor/assistant/receptionist allowed, others 403
// ---------------------------------------------------------------------------

it('doctor can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('assistant can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $assistant = User::factory()->create();
    anamAssignRole($assistant, 'assistant', $setup['clinic']->id);

    $this->actingAs($assistant)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('receptionist can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $receptionist = User::factory()->create();
    anamAssignRole($receptionist, 'receptionist', $setup['clinic']->id);

    $this->actingAs($receptionist)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('owner can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('manager can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $manager = User::factory()->create();
    anamAssignRole($manager, 'manager', $setup['clinic']->id);

    $this->actingAs($manager)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('guest is redirected to login on PUT', function (): void {
    $setup = uaSetup();

    $this->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Core validation
// ---------------------------------------------------------------------------

it('an invalid blood_type value fails validation (422)', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'Z+'])
        ->assertSessionHasErrors('blood_type');
});

it('an out-of-range height_cm fails validation (422)', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['height_cm' => 301])
        ->assertSessionHasErrors('height_cm');
});

it('an empty payload (all-null form) is accepted — every core field is nullable, no active definition is required', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

it('stores blank free-text core fields as null (trim + empty → null)', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'regular_medications' => '',
            'other_chronic' => '   ',
            'allergies' => '',
            'family_history' => 'Anne tarafında diyabet',
        ])
        ->assertRedirect();

    $anamnesis = $setup['patient']->fresh()->anamnesis;

    expect($anamnesis->regular_medications)->toBeNull()
        ->and($anamnesis->other_chronic)->toBeNull()
        ->and($anamnesis->allergies)->toBeNull()
        // A real value is trimmed but kept.
        ->and($anamnesis->family_history)->toBe('Anne tarafında diyabet');

    // The blank-normalized fields are omitted from the PDF; the filled one is rendered.
    $html = app(AnamnesisReportService::class)
        ->build($setup['patient']->fresh())
        ->getHtml();

    expect($html)->toContain('Anne tarafında diyabet')
        ->not->toContain(__('health.fields.regular_medications'));
});

it('a clinic on a non-podiatry vertical saves the core anamnesis successfully (no vertical mismatch)', function (): void {
    $vertical = Vertical::factory()->create(['slug' => 'dermatology']);
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $patient), ['blood_type' => 'A+'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($patient->fresh()->anamnesis->blood_type)->toBe('A+');
});

// ---------------------------------------------------------------------------
// `extra` — round trip, dropped unknown keys, partial-submit preservation,
// required/inactive definitions, per-type validation
// ---------------------------------------------------------------------------

it('extra round-trips for every field type', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'note_text' => 'Serbest metin',
                'flag_bool' => true,
                'sel_choice' => 'a',
                'multi_choice' => ['a', 'b'],
                'num_value' => 42,
                'date_value' => '2026-01-15',
                'required_note' => 'zorunlu değer',
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $extra = $setup['patient']->fresh()->anamnesis->extra;

    expect($extra['note_text'])->toBe('Serbest metin')
        ->and($extra['flag_bool'])->toBeTrue()
        ->and($extra['sel_choice'])->toBe('a')
        ->and($extra['multi_choice'])->toBe(['a', 'b'])
        ->and($extra['num_value'])->toBe(42)
        ->and($extra['date_value'])->toBe('2026-01-15');
});

it('a submitted blank value clears a previously stored extra answer', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'note_text' => 'ilk değer',
                'multi_choice' => ['a', 'b'],
                'required_note' => 'zorunlu',
            ],
        ])
        ->assertRedirect();

    // Blanking (not omitting) note_text/multi_choice must clear the stored answer, not
    // leave the previous value in place via the array_merge that preserves untouched keys.
    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'note_text' => '',
                'multi_choice' => [],
                'required_note' => 'zorunlu',
            ],
        ])
        ->assertRedirect();

    $extra = $setup['patient']->fresh()->anamnesis->extra;

    expect($extra)->not->toHaveKey('note_text')
        ->and($extra)->not->toHaveKey('multi_choice')
        ->and($extra['required_note'])->toBe('zorunlu');
});

it('an extra key with no active definition is silently dropped, never persisted', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'unknown_key' => 'ghost value',
                'note_text' => 'gerçek',
                'required_note' => 'zorunlu',
            ],
        ])
        ->assertRedirect();

    $extra = $setup['patient']->fresh()->anamnesis->extra;

    expect($extra)->not->toHaveKey('unknown_key')
        ->and($extra['note_text'])->toBe('gerçek');
});

it('a partial submit does not wipe untouched extra keys', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'note_text' => 'ilk değer',
                'flag_bool' => true,
                'required_note' => 'zorunlu',
            ],
        ])
        ->assertRedirect();

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => [
                'flag_bool' => false,
                'required_note' => 'zorunlu',
            ],
        ])
        ->assertRedirect();

    $extra = $setup['patient']->fresh()->anamnesis->extra;

    expect($extra['note_text'])->toBe('ilk değer')
        ->and($extra['flag_bool'])->toBeFalse()
        ->and($extra['required_note'])->toBe('zorunlu');
});

it('a required active definition rejects a blank value with an extra.<key> error', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => ['required_note' => ''],
        ])
        ->assertSessionHasErrors('extra.required_note');
});

it('an inactive definition is neither required nor present in the props, but its stored value survives a submit', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    Anamnesis::factory()->create([
        'clinic_id' => $setup['clinic']->id,
        'patient_id' => $setup['patient']->id,
        'extra' => ['retired_note' => 'eski değer'],
    ]);

    // Omits `retired_note` entirely — no active definition exists to require or validate it.
    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => ['required_note' => 'zorunlu'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($setup['patient']->fresh()->anamnesis->extra['retired_note'])->toBe('eski değer');

    $this->actingAs($doctor)
        ->get(route('patients.show', $setup['patient']))
        ->assertInertia(fn ($page) => $page
            ->where('anamnesisFields', fn ($fields) => collect($fields)->pluck('key')->doesntContain('retired_note')));
});

it('extra values fail 422 per type: select outside options, number non-numeric, date malformed', function (): void {
    $setup = uaFieldsSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => ['sel_choice' => 'not-an-option', 'required_note' => 'zorunlu'],
        ])
        ->assertSessionHasErrors('extra.sel_choice');

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => ['num_value' => 'not-a-number', 'required_note' => 'zorunlu'],
        ])
        ->assertSessionHasErrors('extra.num_value');

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'extra' => ['date_value' => 'not-a-date', 'required_note' => 'zorunlu'],
        ])
        ->assertSessionHasErrors('extra.date_value');
});

// ---------------------------------------------------------------------------
// Inertia prop contract — patients.show / treatments.process expose `anamnesis`
// ---------------------------------------------------------------------------

it('patients.show exposes anamnesis=null before any save', function (): void {
    $setup = uaSetup();
    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.show', $setup['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->where('anamnesis', null));
});

it('patients.show exposes the filled anamnesis fields after a save', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'AB-'])
        ->assertRedirect();

    $this->actingAs($doctor)
        ->get(route('patients.show', $setup['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('anamnesis.blood_type', 'AB-'));
});

it('patients.show emits auth.permissions containing anamnesis.update for assistant and owner', function (): void {
    $setup = uaSetup();
    $assistant = User::factory()->create();
    anamAssignRole($assistant, 'assistant', $setup['clinic']->id);

    $this->actingAs($assistant)
        ->get(route('patients.show', $setup['patient']))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('anamnesis.update')));

    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.show', $setup['patient']))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('anamnesis.update')));
});

it('treatments.process exposes anamnesis=null and patient.gender before any save', function (): void {
    $setup = uaProcessSetup();

    $this->actingAs($setup['owner'])
        ->get(route('treatments.process', $setup['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->where('anamnesis', null)
            ->where('treatment.patient.gender', 'female'));
});

it('treatments.process exposes the filled anamnesis after a save', function (): void {
    $setup = uaProcessSetup();

    Anamnesis::factory()->create([
        'clinic_id' => $setup['clinic']->id,
        'patient_id' => $setup['patient']->id,
        'blood_type' => 'B+',
    ]);

    $this->actingAs($setup['owner'])
        ->get(route('treatments.process', $setup['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->where('anamnesis.blood_type', 'B+')
            ->where('treatment.patient.gender', 'female'));
});
