<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\StatusLog;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Support\ClinicContext;
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
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function obRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a fixture: clinic, actor (role), doctor-user, doctor profile.
 * The actor's clinic-scoped role is assigned. Returns [$clinic, $actor, $doctor].
 *
 * @return array{0: Clinic, 1: User, 2: Doctor}
 */
function obFixture(string $actorRole = 'owner'): array
{
    $clinic = Clinic::factory()->create();
    $actor = User::factory()->create();
    obRole($actor, $actorRole, $clinic->id);

    $doctorUser = User::factory()->create();
    obRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    return [$clinic, $actor, $doctor];
}

/**
 * Create a future appointment for a doctor in a given clinic.
 */
function obFutureAppt(Clinic $clinic, Doctor $doctor, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return Appointment::factory()->withStatus($status)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);
}

/**
 * Create a past appointment for a doctor in a given clinic.
 */
function obPastAppt(Clinic $clinic, Doctor $doctor, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return Appointment::factory()->past()->withStatus($status)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);
}

// ---------------------------------------------------------------------------
// Happy path — cancel_appointments = true
// ---------------------------------------------------------------------------

it('owner offboards a doctor with cancel_appointments=true: doctor is marked departed', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), [
            'cancel_appointments' => true,
            'reason' => 'Doktor ayrıldı.',
        ])
        ->assertRedirect(route('doctors.index'));

    $fresh = $doctor->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->left_at)->not->toBeNull();
});

it('offboarding revokes the clinic-scoped doctor role', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => true]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    expect($doctor->user->fresh()->hasRole('doctor'))->toBeFalse();
});

it('offboarding cancels future Confirmed and Rescheduled appointments', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $confirmed = obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);
    $rescheduled = obFutureAppt($clinic, $doctor, AppointmentStatus::Rescheduled);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), [
            'cancel_appointments' => true,
            'reason' => 'Test reason',
        ]);

    expect($confirmed->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($rescheduled->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('offboarding writes a status_log for each cancelled appointment', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $confirmed = obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);
    $rescheduled = obFutureAppt($clinic, $doctor, AppointmentStatus::Rescheduled);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), [
            'cancel_appointments' => true,
            'reason' => 'Offboard reason',
        ]);

    $morphType = (new Appointment)->getMorphClass();

    $log1 = StatusLog::where('loggable_id', $confirmed->id)
        ->where('loggable_type', $morphType)
        ->first();

    expect($log1)->not->toBeNull()
        ->and($log1->from_status)->toBe(AppointmentStatus::Confirmed->value)
        ->and($log1->to_status)->toBe(AppointmentStatus::Cancelled->value)
        ->and($log1->by_user_id)->toBe($owner->id)
        ->and($log1->reason)->toBe('Offboard reason');

    $log2 = StatusLog::where('loggable_id', $rescheduled->id)
        ->where('loggable_type', $morphType)
        ->first();

    expect($log2)->not->toBeNull()
        ->and($log2->from_status)->toBe(AppointmentStatus::Rescheduled->value)
        ->and($log2->to_status)->toBe(AppointmentStatus::Cancelled->value)
        ->and($log2->by_user_id)->toBe($owner->id)
        ->and($log2->reason)->toBe('Offboard reason');
});

it('offboarding does not touch past appointments', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $past = obPastAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => true]);

    expect($past->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('offboarding does not touch terminal-status appointments (Completed, Arrived, already Cancelled)', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $completed = obPastAppt($clinic, $doctor, AppointmentStatus::Completed);
    $arrived = obFutureAppt($clinic, $doctor, AppointmentStatus::Arrived);
    $alreadyCancelled = obFutureAppt($clinic, $doctor, AppointmentStatus::Cancelled);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => true]);

    expect($completed->fresh()->status)->toBe(AppointmentStatus::Completed)
        ->and($arrived->fresh()->status)->toBe(AppointmentStatus::Arrived)
        ->and($alreadyCancelled->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('offboard flashes a success toast', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => true])
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Soft-deleted patient — the appointment outlives the patient
// ---------------------------------------------------------------------------

it('offboards a doctor whose future appointment belongs to a soft-deleted patient', function (): void {
    Queue::fake();

    [$clinic, $owner, $doctor] = obFixture('owner');

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinmiş',
        'last_name' => 'Hasta',
        'phone' => '+905321234567',
    ]);

    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $patient->delete();

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), [
            'cancel_appointments' => true,
            'reason' => 'Doktor ayrıldı.',
        ])
        ->assertRedirect(route('doctors.index'));

    $fresh = $doctor->fresh();
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($fresh->is_active)->toBeFalse()
        ->and($fresh->left_at)->not->toBeNull();

    // Without withTrashed() the eager-loaded patient relation is null, the cancel SMS
    // throws inside its own swallow-all try/catch, and the patient is silently never told.
    Queue::assertPushed(SendSmsJob::class, 1);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient): bool {
        return $job->message->type === SmsType::AppointmentCancelled
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id
            && $job->message->phone === '+905321234567';
    });
});

// ---------------------------------------------------------------------------
// Guard — cancel_appointments=false with upcoming appointments
// ---------------------------------------------------------------------------

it('offboard with cancel_appointments=false while appointments exist → 422 keyed on cancel_appointments', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false])
        ->assertSessionHasErrors('cancel_appointments');
});

it('the guard leaves doctor active when cancel_appointments=false with upcoming appointments', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false]);

    $fresh = $doctor->fresh();
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->left_at)->toBeNull();
});

it('the guard leaves appointments untouched when cancel_appointments=false with upcoming appointments', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $appt = obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false]);

    expect($appt->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// No-appointments path — cancel_appointments=false, nothing to cancel
// ---------------------------------------------------------------------------

it('offboard with no upcoming appointments succeeds even with cancel_appointments=false', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obPastAppt($clinic, $doctor, AppointmentStatus::Completed);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false])
        ->assertRedirect(route('doctors.index'));

    $fresh = $doctor->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->left_at)->not->toBeNull();
});

it('no-appointments offboard does not create any status_log rows', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false]);

    expect(StatusLog::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Preview endpoint
// ---------------------------------------------------------------------------

it('offboard-preview returns the correct upcoming_appointments_count', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    obFutureAppt($clinic, $doctor, AppointmentStatus::Confirmed);
    obFutureAppt($clinic, $doctor, AppointmentStatus::Rescheduled);
    obPastAppt($clinic, $doctor, AppointmentStatus::Confirmed); // past — excluded

    $this->actingAs($owner)
        ->get(route('doctors.offboard.preview', $doctor))
        ->assertOk()
        ->assertJson([
            'upcoming_appointments_count' => 2,
            'is_offboarded' => false,
        ]);
});

it('offboard-preview returns is_offboarded=true for an already-offboarded doctor', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $doctor->update(['is_active' => false, 'left_at' => now()]);

    $this->actingAs($owner)
        ->get(route('doctors.offboard.preview', $doctor))
        ->assertOk()
        ->assertJson(['is_offboarded' => true]);
});

it('offboard-preview is read-only and does not mutate doctor state', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    obFutureAppt($clinic, $doctor);

    $this->actingAs($owner)
        ->get(route('doctors.offboard.preview', $doctor));

    expect($doctor->fresh()->is_active)->toBeTrue()
        ->and($doctor->fresh()->left_at)->toBeNull();
});

it('offboard-preview includes the display_name in the response', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $response = $this->actingAs($owner)
        ->get(route('doctors.offboard.preview', $doctor))
        ->assertOk();

    expect($response->json())->toHaveKey('display_name');
});

// ---------------------------------------------------------------------------
// Idempotency — already-offboarded doctor
// ---------------------------------------------------------------------------

it('offboarding an already-offboarded doctor returns a validation error', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $doctor->update(['is_active' => false, 'left_at' => now()->subDay()]);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false])
        ->assertSessionHasErrors('cancel_appointments');
});

it('re-offboarding leaves the original left_at unchanged', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $originalLeftAt = now()->subDay()->startOfSecond();
    $doctor->update(['is_active' => false, 'left_at' => $originalLeftAt]);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false]);

    expect($doctor->fresh()->left_at->startOfSecond()->toIso8601String())
        ->toBe($originalLeftAt->toIso8601String());
});

// ---------------------------------------------------------------------------
// Self-offboard guard
// ---------------------------------------------------------------------------

it('an actor cannot offboard their own doctor profile — 422 with cannot_offboard_self', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    // Give the owner their own doctor profile to attempt self-offboard
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $ownDoctor), ['cancel_appointments' => false])
        ->assertSessionHasErrors('cancel_appointments');
});

it('self-offboard guard leaves the own doctor profile active and undeparted', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $ownDoctor), ['cancel_appointments' => false]);

    expect($ownDoctor->fresh()->is_active)->toBeTrue()
        ->and($ownDoctor->fresh()->left_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Permission matrix — authorization
// ---------------------------------------------------------------------------

it('doctor role gets 403 on offboard-preview', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'doctor', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->get(route('doctors.offboard.preview', $targetDoctor))
        ->assertForbidden();
});

it('doctor role gets 403 on offboard action', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'doctor', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->post(route('doctors.offboard', $targetDoctor), ['cancel_appointments' => false])
        ->assertForbidden();
});

it('receptionist gets 403 on offboard-preview', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'receptionist', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->get(route('doctors.offboard.preview', $targetDoctor))
        ->assertForbidden();
});

it('receptionist gets 403 on offboard action', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'receptionist', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->post(route('doctors.offboard', $targetDoctor), ['cancel_appointments' => false])
        ->assertForbidden();
});

it('assistant gets 403 on offboard-preview', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'assistant', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->get(route('doctors.offboard.preview', $targetDoctor))
        ->assertForbidden();
});

it('assistant gets 403 on offboard action', function (): void {
    $clinic = Clinic::factory()->create();
    $actorUser = User::factory()->create();
    obRole($actorUser, 'assistant', $clinic->id);

    $doctorUser = User::factory()->create();
    $targetDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($actorUser)
        ->post(route('doctors.offboard', $targetDoctor), ['cancel_appointments' => false])
        ->assertForbidden();
});

it('owner is allowed on offboard-preview', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $this->actingAs($owner)
        ->get(route('doctors.offboard.preview', $doctor))
        ->assertOk();
});

it('owner can offboard a doctor successfully', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false])
        ->assertRedirect(route('doctors.index'));
});

it('manager is allowed on offboard-preview', function (): void {
    [$clinic, $manager, $doctor] = obFixture('manager');

    $this->actingAs($manager)
        ->get(route('doctors.offboard.preview', $doctor))
        ->assertOk();
});

it('manager can offboard a doctor successfully', function (): void {
    [$clinic, $manager, $doctor] = obFixture('manager');

    $this->actingAs($manager)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false])
        ->assertRedirect(route('doctors.index'));
});

// ---------------------------------------------------------------------------
// DoctorResource shape — index includes left_at and is_offboarded
// ---------------------------------------------------------------------------

it('GET /doctors index includes left_at and is_offboarded for each doctor', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('doctors', 1)
            ->has('doctors.0.left_at')
            ->has('doctors.0.is_offboarded')
        );
});

it('is_offboarded is false for an active doctor on the index', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('doctors.0.is_offboarded', false)
            ->where('doctors.0.left_at', null)
        );
});

it('is_offboarded is true on the index after offboarding', function (): void {
    [$clinic, $owner, $doctor] = obFixture('owner');

    // Offboard first
    $this->actingAs($owner)
        ->post(route('doctors.offboard', $doctor), ['cancel_appointments' => false]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles')->unsetRelation('permissions');

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('doctors.0.is_offboarded', true)
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — mandatory
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on offboard-preview for a clinic B doctor', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    obRole($ownerA, 'owner', $clinicA->id);

    $userB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB->id]);

    $this->actingAs($ownerA)
        ->get(route('doctors.offboard.preview', $doctorB))
        ->assertNotFound();
});

it('clinic A owner gets 404 on offboard for a clinic B doctor', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    obRole($ownerA, 'owner', $clinicA->id);

    $userB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB->id]);

    $this->actingAs($ownerA)
        ->post(route('doctors.offboard', $doctorB), ['cancel_appointments' => true])
        ->assertNotFound();
});

it('cross-tenant offboard attempt leaves clinic B doctor active', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    obRole($ownerA, 'owner', $clinicA->id);

    $userB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB->id]);

    $this->actingAs($ownerA)
        ->post(route('doctors.offboard', $doctorB), ['cancel_appointments' => true]);

    expect($doctorB->fresh()->is_active)->toBeTrue()
        ->and($doctorB->fresh()->left_at)->toBeNull();
});

it('offboarding clinic A doctor does not cancel clinic B future appointments in the same date window', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    obRole($ownerA, 'owner', $clinicA->id);

    // Clinic A doctor (to be offboarded)
    $userA = User::factory()->create();
    obRole($userA, 'doctor', $clinicA->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $userA->id]);
    obFutureAppt($clinicA, $doctorA, AppointmentStatus::Confirmed);

    // Clinic B doctor with a future appointment — must not be touched
    $userB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB->id]);
    $apptB = obFutureAppt($clinicB, $doctorB, AppointmentStatus::Confirmed);

    $this->actingAs($ownerA)
        ->post(route('doctors.offboard', $doctorA), ['cancel_appointments' => true])
        ->assertRedirect(route('doctors.index'));

    expect($apptB->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});
