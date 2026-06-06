<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleException;
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
function caRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Clinic-local ISO string for next Monday at 10:00 (Europe/Istanbul).
 * Falls within the default working hours (09:00-19:00) and outside the break (12:00-13:30).
 */
function caNextMondaySlot(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';
}

/**
 * Build a valid POST /appointments payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function caPayload(int $patientId, int $doctorId, array $overrides = []): array
{
    return array_merge([
        'patient_mode' => 'existing',
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'service_id' => null,
        'starts_at' => caNextMondaySlot(),
        'duration_minutes' => null,
        'is_walk_in' => false,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /appointments/create — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/create', function (): void {
    $this->get(route('appointments.create'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointments/create and the Create component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/Create')
            ->has('doctors')
            ->has('services')
            ->has('defaultSlotDuration')
            ->has('workingHours')
            ->has('timezone')
            ->has('preselectedPatient')
        );
});

it('manager can access GET /appointments/create', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    caRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('appointments.create'))
        ->assertOk();
});

it('doctor can access GET /appointments/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    caRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('appointments.create'))
        ->assertOk();
});

it('receptionist can access GET /appointments/create', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    caRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('appointments.create'))
        ->assertOk();
});

it('assistant gets 403 on GET /appointments/create (no appointments.create permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    caRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointments.create'))
        ->assertForbidden();
});

it('create page props include clinic timezone and default slot duration', function (): void {
    $clinic = Clinic::factory()->create([
        'timezone' => 'Europe/Istanbul',
        'default_slot_duration_minutes' => 45,
    ]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.create'))
        ->assertInertia(fn ($page) => $page
            ->where('timezone', 'Europe/Istanbul')
            ->where('defaultSlotDuration', 45)
        );
});

it('preselectedPatient is populated when patient_id query param is present', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('appointments.create', ['patient_id' => $patient->id]))
        ->assertInertia(fn ($page) => $page->where('preselectedPatient.id', $patient->id));
});

it('preselectedPatient is null when no patient_id is given', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.create'))
        ->assertInertia(fn ($page) => $page->where('preselectedPatient', null));
});

// ---------------------------------------------------------------------------
// POST /appointments — core creation behavior
// ---------------------------------------------------------------------------

it('owner can create a Confirmed appointment and is redirected back to create', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect(route('appointments.create'));

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('patient_id', $patient->id)
        ->where('doctor_id', $doctor->id)
        ->first();

    expect($appointment)->not->toBeNull()
        ->and($appointment->status)->toBe(AppointmentStatus::Confirmed);
});

it('receptionist can create an appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    caRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('patient_id', $patient->id)
        ->exists()
    )->toBeTrue();
});

it('assistant gets 403 on POST /appointments', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    caRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertForbidden();
});

it('ends_at is computed from service duration_minutes when service_id is provided', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'service_id' => $service->id,
        ]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull()
        ->and((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(45);
});

it('persists the chosen service_id on the appointment (visit intent)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'service_id' => $service->id,
        ]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment->service_id)->toBe($service->id);
});

it('leaves service_id null when no service is chosen', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, ['service_id' => null]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment->service_id)->toBeNull();
});

it('ends_at falls back to clinic default_slot_duration_minutes when no service or override', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull()
        ->and((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(30);
});

it('ends_at uses explicit duration_minutes override over service and default', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'service_id' => $service->id,
            'duration_minutes' => 60,
        ]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull()
        ->and((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(60);
});

it('store writes a null → confirmed status_log row with the actor as by_user_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('successful store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertSessionHas('toasts');
});

it('store sets created_by to the acting user id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment->created_by)->toBe($owner->id);
});

it('no treatment is created when an appointment is stored (only one status_log written)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect();

    // Exactly one status_log for the appointment. A treatment creation would add a second.
    expect(StatusLog::withoutGlobalScopes()->count())->toBe(1)
        ->and(StatusLog::withoutGlobalScopes()->value('loggable_type'))->toBe('appointment');
});

// ---------------------------------------------------------------------------
// POST /appointments — availability layer 1 (working hours)
// ---------------------------------------------------------------------------

it('rejects a slot before clinic opening hours with a 422 on starts_at', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // 08:00 Istanbul is before opening (09:00)
    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => $earlySlot,
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('rejects a slot extending past closing time with a 422 on starts_at', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // 30-min slot starting at 19:00 extends to 19:30, past closing (19:00)
    $lateSlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 19:00:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => $lateSlot,
            'duration_minutes' => 30,
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('rejects a slot that overlaps the break window with a 422 on starts_at', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // 12:15 Istanbul falls inside the break (12:00-13:30)
    $breakSlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 12:15:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => $breakSlot,
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('rejects a slot on a closed day (Sunday) with a 422 on starts_at', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Sunday is closed in defaultWorkingHours
    $sundaySlot = Carbon::now('Europe/Istanbul')->next(Carbon::SUNDAY)->format('Y-m-d').' 10:00:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => $sundaySlot,
        ]))
        ->assertSessionHasErrors('starts_at');
});

// ---------------------------------------------------------------------------
// POST /appointments — availability layer 2 (schedule exceptions)
// ---------------------------------------------------------------------------

it('non-walk-in is rejected when a schedule exception overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30), // 09:30 Istanbul
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),   // 10:15 Istanbul — overlaps
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => false,
        ]))
        ->assertSessionHasErrors('starts_at');
});

// ---------------------------------------------------------------------------
// POST /appointments — availability layer 3 (appointment conflicts)
// ---------------------------------------------------------------------------

it('non-walk-in is rejected when a Confirmed appointment overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('non-walk-in is rejected when an Arrived appointment overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('back-to-back slots are allowed (previous ends_at equals new starts_at)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Existing appointment ends exactly when the new slot starts → strict overlap, no conflict
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30), // 09:30 Istanbul
        'ends_at' => $slotStartUtc->copy(),                   // 10:00 Istanbul
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ]))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count()
    )->toBe(2);
});

// ---------------------------------------------------------------------------
// POST /appointments — walk-in bypasses layers 2 & 3
// ---------------------------------------------------------------------------

it('walk-in bypasses layer 2: schedule exception overlap does not block booking', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => true,
        ]))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('is_walk_in', true)
        ->exists()
    )->toBeTrue();
});

it('walk-in bypasses layer 3: overlapping Confirmed appointment does not block booking', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => true,
        ]))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('is_walk_in', true)
        ->exists()
    )->toBeTrue();
});

it('walk-in still enforces layer 1: slot outside working hours is rejected', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'starts_at' => $earlySlot,
            'is_walk_in' => true,
        ]))
        ->assertSessionHasErrors('starts_at');
});

// ---------------------------------------------------------------------------
// POST /appointments — FormRequest field validation
// ---------------------------------------------------------------------------

it('store requires patient_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), [
            'patient_mode' => 'existing',
            'doctor_id' => $doctor->id,
            'starts_at' => caNextMondaySlot(),
        ])
        ->assertSessionHasErrors('patient_id');
});

it('store requires doctor_id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), [
            'patient_id' => $patient->id,
            'starts_at' => caNextMondaySlot(),
        ])
        ->assertSessionHasErrors('doctor_id');
});

it('store requires starts_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, ['starts_at' => null]))
        ->assertSessionHasErrors('starts_at');
});

it('store rejects duration_minutes below 5', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, ['duration_minutes' => 4]))
        ->assertSessionHasErrors('duration_minutes');
});

it('store rejects duration_minutes above 480', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, ['duration_minutes' => 481]))
        ->assertSessionHasErrors('duration_minutes');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('store rejects a patient_id that belongs to a different clinic (422)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    caRole($ownerA, 'owner', $clinicA->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUser->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->post(route('appointments.store'), caPayload($patientB->id, $doctor->id))
        ->assertSessionHasErrors('patient_id');

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinicA->id)->exists())->toBeFalse();
});

it('store rejects a doctor_id that belongs to a different clinic (422)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    caRole($ownerA, 'owner', $clinicA->id);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($ownerA)
        ->post(route('appointments.store'), caPayload($patientA->id, $doctorB->id))
        ->assertSessionHasErrors('doctor_id');

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinicA->id)->exists())->toBeFalse();
});

it("clinic B's overlapping appointment does not block clinic A's doctor's slot", function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    caRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Clinic B's doctor has a conflicting appointment — must NOT affect clinic A
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($ownerA)
        ->post(route('appointments.store'), caPayload($patientA->id, $doctorA->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ]))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('doctor_id', $doctorA->id)
        ->exists()
    )->toBeTrue();
});

it("clinic B's schedule exception does not block clinic A's doctor's slot", function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    caRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Clinic B's doctor has a schedule exception overlapping the slot
    ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($ownerA)
        ->post(route('appointments.store'), caPayload($patientA->id, $doctorA->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ]))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('doctor_id', $doctorA->id)
        ->exists()
    )->toBeTrue();
});

// ---------------------------------------------------------------------------
// POST /appointments — inline new-patient quick-create
// ---------------------------------------------------------------------------

it('creates a new patient and the appointment atomically in new-patient mode', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload(0, $doctor->id, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => [
                'first_name' => 'Yeni',
                'last_name' => 'Hasta',
                'phone' => '0532 111 22 33',
                'email' => 'yeni@example.com',
            ],
        ]))
        ->assertRedirect(route('appointments.create'));

    $patient = Patient::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('first_name', 'Yeni')
        ->where('last_name', 'Hasta')
        ->first();

    expect($patient)->not->toBeNull();

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('patient_id', $patient->id)
        ->exists()
    )->toBeTrue();
});

it('new-patient mode requires first and last name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload(0, $doctor->id, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'new_patient' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => ''],
        ]))
        ->assertSessionHasErrors(['new_patient.first_name', 'new_patient.last_name']);

    // The conditional-required message must not leak the internal patient_mode field/value.
    $message = session('errors')->get('new_patient.first_name')[0];
    expect($message)->not->toContain('patient_mode');

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('rolls back the new patient when the slot is unavailable', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    // 08:00 is before opening — availability fails before the patient is committed.
    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload(0, $doctor->id, [
            'patient_mode' => 'new',
            'patient_id' => null,
            'starts_at' => $earlySlot,
            'new_patient' => [
                'first_name' => 'Rollback',
                'last_name' => 'Test',
                'phone' => '',
                'email' => '',
            ],
        ]))
        ->assertSessionHasErrors('starts_at');

    expect(Patient::withoutGlobalScopes()->where('first_name', 'Rollback')->exists())->toBeFalse();
});

it('store requires patient_mode', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $payload = caPayload($patient->id, $doctor->id);
    unset($payload['patient_mode']);

    $this->actingAs($owner)
        ->post(route('appointments.store'), $payload)
        ->assertSessionHasErrors('patient_mode');
});

// ---------------------------------------------------------------------------
// POST /appointments — cross-doctor booking (appointments.assignDoctor)
// ---------------------------------------------------------------------------

it('a doctor without assignDoctor can book for their own profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    caRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctorUser)
        ->post(route('appointments.store'), caPayload($patient->id, $ownDoctor->id))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $ownDoctor->id)
        ->exists()
    )->toBeTrue();
});

it('a doctor without assignDoctor cannot book for another doctor (422 on doctor_id)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    caRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctorUser)
        ->post(route('appointments.store'), caPayload($patient->id, $otherDoctor->id))
        ->assertSessionHasErrors('doctor_id');

    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('a receptionist (has assignDoctor) can book for any doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    caRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id))
        ->assertRedirect(route('appointments.create'));

    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $doctor->id)
        ->exists()
    )->toBeTrue();
});

// ---------------------------------------------------------------------------
// POST /appointments — appointment_type_id integration
// ---------------------------------------------------------------------------

it('posting with appointment_type_id persists it on the appointment', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'default_duration_minutes' => 40,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'appointment_type_id' => $type->id,
        ]))
        ->assertRedirect(route('appointments.create'));

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull()
        ->and($appointment->appointment_type_id)->toBe($type->id);
});

it('ends_at is derived from appointment type default duration when no service or explicit override', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'default_duration_minutes' => 40,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'appointment_type_id' => $type->id,
        ]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    expect($appointment)->not->toBeNull()
        ->and((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(40);
});

it('service duration beats appointment type default in the priority chain', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);
    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'default_duration_minutes' => 60,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'service_id' => $service->id,
            'appointment_type_id' => $type->id,
        ]))
        ->assertRedirect();

    $appointment = Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->latest()
        ->first();

    // Service (45 min) wins over type default (60 min).
    expect($appointment)->not->toBeNull()
        ->and((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(45);
});

it('store rejects an inactive appointment_type_id with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $inactiveType = AppointmentType::factory()->inactive()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'appointment_type_id' => $inactiveType->id,
        ]))
        ->assertSessionHasErrors('appointment_type_id');
});

it('store rejects an appointment_type_id belonging to another clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinicA->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $typeB = AppointmentType::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), caPayload($patient->id, $doctor->id, [
            'appointment_type_id' => $typeB->id,
        ]))
        ->assertSessionHasErrors('appointment_type_id');
});

it('GET /appointments/create exposes active appointment types in appointmentTypes prop', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'is_active' => true,
    ]);
    AppointmentType::factory()->inactive()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes', 1)
        );
});
