<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
function smsLogRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Insert an SmsLog row with sensible defaults.
 * Supports passing `created_at` as a string to override the auto-set timestamp
 * (uses a raw DB update after creation to bypass Laravel's timestamp auto-fill).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeSmsLog(array $attributes = []): SmsLog
{
    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $log = SmsLog::create(array_merge([
        'clinic_id' => null,
        'patient_id' => null,
        'phone' => '+905301234567',
        'type' => SmsType::AppointmentCreated->value,
        'body' => 'Test SMS body',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'scheduled_at' => null,
        'sent_at' => now(),
    ], $attributes));

    if ($createdAt !== null) {
        // Raw update to bypass Eloquent's automatic timestamp management.
        DB::table('sms_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
        $log->created_at = $createdAt;
    }

    return $log;
}

// ---------------------------------------------------------------------------
// GET /sms-logs — access control
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /sms-logs', function (): void {
    $this->get(route('sms-logs.index'))
        ->assertRedirect(route('login'));
});

it('owner can GET /sms-logs and the sms-logs/Index component renders', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sms-logs/Index')
            ->has('smsLogs')
            ->has('query')
        );
});

it('manager can GET /sms-logs', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    smsLogRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('sms-logs.index'))
        ->assertOk();
});

it('receptionist can GET /sms-logs', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    smsLogRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('sms-logs.index'))
        ->assertOk();
});

it('doctor is 403 on GET /sms-logs', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    smsLogRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->get(route('sms-logs.index'))
        ->assertForbidden();
});

it('assistant is 403 on GET /sms-logs', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    smsLogRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('sms-logs.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Index — data & pagination
// ---------------------------------------------------------------------------

it('index returns only the active clinic rows, newest-first', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $older = makeSmsLog(['clinic_id' => $clinic->id, 'body' => 'Older', 'created_at' => '2025-01-01 08:00:00']);
    $newer = makeSmsLog(['clinic_id' => $clinic->id, 'body' => 'Newer', 'created_at' => '2025-06-01 08:00:00']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.data.0.id', $newer->id)
            ->where('smsLogs.data.1.id', $older->id)
        );
});

it('index returns pagination meta', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog(['clinic_id' => $clinic->id]);
    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905309999999']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('smsLogs.meta.total')
            ->has('smsLogs.meta.per_page')
            ->has('smsLogs.meta.current_page')
            ->where('smsLogs.meta.total', 2)
        );
});

// ---------------------------------------------------------------------------
// Index — SmsLogResource row shape
// ---------------------------------------------------------------------------

it('row contains the expected keys from SmsLogResource', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
    ]);

    makeSmsLog([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'type' => SmsType::Reminder24h->value,
        'status' => SmsStatus::Sent->value,
        'phone' => '+905301234567',
        'body' => 'Reminder body',
        'error' => null,
        'sent_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('smsLogs.data.0.id')
            ->has('smsLogs.data.0.type')
            ->has('smsLogs.data.0.status')
            ->has('smsLogs.data.0.phone')
            ->has('smsLogs.data.0.patient_name')
            ->has('smsLogs.data.0.patient_id')
            ->has('smsLogs.data.0.body')
            ->has('smsLogs.data.0.error')
            ->has('smsLogs.data.0.created_at')
            ->has('smsLogs.data.0.sent_at')
        );
});

it('patient_name is the full name when patient_id is set', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Mehmet',
        'last_name' => 'Demir',
    ]);

    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id]);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.data.0.patient_name', 'Mehmet Demir')
            ->where('smsLogs.data.0.patient_id', $patient->id)
        );
});

it('patient_name is null when patient_id is null', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => null]);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.data.0.patient_name', null)
            ->where('smsLogs.data.0.patient_id', null)
        );
});

it('error field carries the failure reason for failed rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog([
        'clinic_id' => $clinic->id,
        'status' => SmsStatus::Failed->value,
        'error' => 'Provider timeout',
    ]);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.data.0.error', 'Provider timeout')
        );
});

// ---------------------------------------------------------------------------
// Index — filters
// ---------------------------------------------------------------------------

it('filter by status narrows to matching rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog(['clinic_id' => $clinic->id, 'status' => SmsStatus::Sent->value, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinic->id, 'status' => SmsStatus::Failed->value, 'phone' => '+905302222222']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['status' => 'failed']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.status', 'failed')
        );
});

it('filter by type narrows to matching rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog(['clinic_id' => $clinic->id, 'type' => SmsType::Reminder24h->value, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinic->id, 'type' => SmsType::AppointmentCreated->value, 'phone' => '+905302222222']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['type' => 'reminder_24h']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.type', 'reminder_24h')
        );
});

it('filter by date range returns rows within the range', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    // Fixed past date well outside the range (no timezone edge cases in SQLite).
    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905301111111', 'created_at' => '2024-01-01 10:00:00']);
    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905302222222', 'created_at' => '2025-06-15 10:00:00']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index', [
            'filter' => [
                'start_date' => '2025-06-01',
                'end_date' => '2025-06-30',
            ],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.phone', '+905302222222')
        );
});

it('search by phone narrows to matching rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905302222222']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['search' => '2222']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.phone', '+905302222222')
        );
});

it('search by patient name narrows to that patient rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $irmak = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Irmak',
        'last_name' => 'Şahin',
    ]);
    $other = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Kerem',
        'last_name' => 'Demir',
    ]);

    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $irmak->id, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $other->id, 'phone' => '+905302222222']);

    // Lowercase dotless ı must still match "Irmak" (Turkish folding lives in SearchTerm).
    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['search' => 'ırmak']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.phone', '+905301111111')
        );

    // Full name across both columns.
    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['search' => 'Kerem Demir']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('smsLogs.meta.total', 1));
});

it('query prop reflects submitted filter values', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('sms-logs.index', ['filter' => ['status' => 'sent', 'search' => '0530']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.status', 'sent')
            ->where('query.filter.search', '0530')
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user cannot see clinic B sms_logs', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    smsLogRole($ownerA, 'owner', $clinicA->id);

    makeSmsLog(['clinic_id' => $clinicA->id, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinicB->id, 'phone' => '+905302222222']);

    $this->actingAs($ownerA)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.phone', '+905301111111')
        );
});

it('OTP rows (clinic_id = null) are excluded from the clinic list', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    // OTP row: clinic_id = null (platform-level, excluded by ClinicScope)
    makeSmsLog(['clinic_id' => null, 'type' => SmsType::Otp->value, 'phone' => '+905300000000']);

    // A clinic row
    makeSmsLog(['clinic_id' => $clinic->id, 'phone' => '+905301111111']);

    $this->actingAs($owner)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 1)
            ->where('smsLogs.data.0.phone', '+905301111111')
        );
});

it('clinic B owner cannot read clinic A rows even via a direct request', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    smsLogRole($ownerA, 'owner', $clinicA->id);
    smsLogRole($ownerB, 'owner', $clinicB->id);

    makeSmsLog(['clinic_id' => $clinicA->id, 'phone' => '+905301111111']);

    $this->actingAs($ownerB)
        ->get(route('sms-logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.meta.total', 0)
        );
});

// ---------------------------------------------------------------------------
// Patient-show smsLogs prop contract
// ---------------------------------------------------------------------------

it('patients.show includes a smsLogs prop for any user who can view the patient', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('smsLogs')
            ->where('smsLogs.0.patient_id', $patient->id)
        );
});

it('patients.show smsLogs contains only the viewed patient rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $patientA = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinic->id]);

    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patientA->id, 'phone' => '+905301111111']);
    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patientB->id, 'phone' => '+905302222222']);

    $this->actingAs($owner)
        ->get(route('patients.show', $patientA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs', fn ($logs) => count($logs) === 1 && $logs[0]['patient_id'] === $patientA->id)
        );
});

it('patients.show smsLogs is accessible by a doctor (no smsLogs.viewAny needed)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    smsLogRole($doctor, 'doctor', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id]);

    $this->actingAs($doctor)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('smsLogs'));
});

it('patients.show smsLogs is accessible by an assistant (no smsLogs.viewAny needed)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    smsLogRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id]);

    $this->actingAs($assistant)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('smsLogs'));
});

it('patients.show smsLogs rows are newest-first', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsLogRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $older = makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'phone' => '+905301111111', 'created_at' => '2025-01-01 08:00:00']);
    $newer = makeSmsLog(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'phone' => '+905302222222', 'created_at' => '2025-06-01 08:00:00']);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs.0.id', $newer->id)
            ->where('smsLogs.1.id', $older->id)
        );
});

it('patients.show smsLogs excludes clinic B rows from clinic A context', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    smsLogRole($ownerA, 'owner', $clinicA->id);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    makeSmsLog(['clinic_id' => $clinicA->id, 'patient_id' => $patientA->id, 'phone' => '+905301111111']);
    // A row with clinic B for the same patient_id (edge case) must not leak
    makeSmsLog(['clinic_id' => $clinicB->id, 'patient_id' => $patientA->id, 'phone' => '+905302222222']);

    $this->actingAs($ownerA)
        ->get(route('patients.show', $patientA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('smsLogs', fn ($logs) => count($logs) === 1 && $logs[0]['phone'] === '+905301111111')
        );
});

// ---------------------------------------------------------------------------
// Arch — cross-module boundary
// ---------------------------------------------------------------------------

it('PatientController does not directly import Messaging Repositories', function (): void {
    $source = file_get_contents(app_path('Modules/Medical/Http/Controllers/PatientController.php'));

    expect($source)->not->toContain('Messaging\\Repositories')
        ->and($source)->toContain('Messaging\\Contracts\\SmsHistoryContract');
});
