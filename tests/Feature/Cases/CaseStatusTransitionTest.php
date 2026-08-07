<?php

use App\Enums\CaseStatus;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\FollowUpType;
use App\Models\Patient;
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
 * Assign a clinic-scoped role (case-status-transition tests).
 */
function cstRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, doctor, patient, and an open case.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient, case: CaseRecord}
 */
function cstSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cstRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    cstRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient', 'case');
}

/**
 * Force a case into the given initial status (bypassing service rules).
 */
function cstForceStatus(CaseRecord $case, string $status): void
{
    $updates = ['status' => $status];

    if ($status === 'suspended') {
        $updates['suspended_at'] = now();
        $updates['closed_at'] = null;
    } elseif ($status === 'follow_up') {
        $updates['closed_at'] = null;
        $updates['suspended_at'] = null;
    } elseif ($status === 'closed') {
        $updates['closed_at'] = now();
        $updates['suspended_at'] = null;
    } elseif ($status === 'open') {
        $updates['closed_at'] = null;
        $updates['suspended_at'] = null;
    }

    $case->updateQuietly($updates);
    $case->refresh();
}

// ---------------------------------------------------------------------------
// Legal transitions — each edge succeeds and writes a status_log row
// ---------------------------------------------------------------------------

dataset('legal transitions', [
    'open → suspended' => ['open',      'suspended',  []],
    'open → follow_up' => ['open',      'follow_up',  ['due_date' => '2030-01-01']],
    'open → closed' => ['open',      'closed',     []],
    'suspended → open' => ['suspended', 'open',       []],
    'suspended → closed' => ['suspended', 'closed',     []],
    'follow_up → open' => ['follow_up', 'open',       []],
    'follow_up → closed' => ['follow_up', 'closed',     []],
    'closed → open' => ['closed',    'open',       []],
]);

/**
 * Merge the payload for a legal-transition dataset row, adding a valid
 * follow_up_type_id when the target status is `follow_up` (required by the FormRequest).
 */
function cstPayload(CaseRecord $case, string $to, array $extra): array
{
    $payload = array_merge(['status' => $to], $extra);

    if ($to === 'follow_up') {
        $payload['follow_up_type_id'] = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id])->id;
    }

    return $payload;
}

it('accepts legal status transition and persists the new status', function (string $from, string $to, array $extra) {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    cstForceStatus($case, $from);

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), cstPayload($case, $to, $extra))
        ->assertRedirect(route('cases.show', $case));

    expect(CaseRecord::withoutGlobalScopes()->find($case->id)->status->value)->toBe($to);
})->with('legal transitions');

it('writes exactly one status_log row per legal transition', function (string $from, string $to, array $extra) {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    cstForceStatus($case, $from);

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), cstPayload($case, $to, $extra));

    $logCount = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'case')
        ->where('loggable_id', $case->id)
        ->where('from_status', $from)
        ->where('to_status', $to)
        ->count();

    expect($logCount)->toBe(1);
})->with('legal transitions');

it('records the actor user id on the status_log', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'closed']);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'case')
        ->where('loggable_id', $case->id)
        ->where('to_status', 'closed')
        ->first();

    expect($log?->by_user_id)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
// Illegal transitions — 422 and status unchanged
// ---------------------------------------------------------------------------

dataset('illegal transitions', [
    'closed → suspended' => ['closed',    'suspended'],
    'closed → follow_up' => ['closed',    'follow_up'],
    'open → open' => ['open',      'open'],
    'suspended → follow_up' => ['suspended', 'follow_up'],
]);

it('rejects an illegal status transition with 422', function (string $from, string $to) {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    cstForceStatus($case, $from);

    $payload = ['status' => $to];
    if ($to === 'follow_up') {
        $payload['due_date'] = '2030-01-01';
        $payload['follow_up_type_id'] = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id])->id;
    }

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), $payload)
        ->assertSessionHasErrors('status');

    expect(CaseRecord::withoutGlobalScopes()->find($case->id)->status->value)->toBe($from);
})->with('illegal transitions');

// ---------------------------------------------------------------------------
// Follow-up required validation
// ---------------------------------------------------------------------------

it('rejects open → follow_up without a due_date', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    $type = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id]);

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'follow_up', 'follow_up_type_id' => $type->id])
        ->assertSessionHasErrors('due_date');

    expect(CaseRecord::withoutGlobalScopes()->find($case->id)->status)->toBe(CaseStatus::Open);
});

it('rejects open → follow_up without a follow_up_type_id', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'follow_up', 'due_date' => '2030-01-01'])
        ->assertSessionHasErrors('follow_up_type_id');

    expect(CaseRecord::withoutGlobalScopes()->find($case->id)->status)->toBe(CaseStatus::Open);
});

// ---------------------------------------------------------------------------
// Timestamp side-effects
// ---------------------------------------------------------------------------

it('sets closed_at when transitioning to Closed', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'closed']);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->closed_at)->not->toBeNull();
});

it('sets suspended_at when transitioning to Suspended', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'suspended']);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->suspended_at)->not->toBeNull();
});

it('clears closed_at and suspended_at when reopening from Closed', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    cstForceStatus($case, 'closed');

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'open']);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->closed_at)->toBeNull()
        ->and($fresh->suspended_at)->toBeNull();
});

it('clears suspended_at when returning from Suspended to Open', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    cstForceStatus($case, 'suspended');

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'open']);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->suspended_at)->toBeNull();
});

it('creates a follow_ups row when transitioning to FollowUp', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    $type = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id]);
    $date = Carbon::now()->addDays(14)->format('Y-m-d');

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), [
            'status' => 'follow_up',
            'due_date' => $date,
            'note' => 'Check back in two weeks',
            'follow_up_type_id' => $type->id,
        ]);

    $followUp = FollowUp::withoutGlobalScopes()
        ->where('case_id', $case->id)
        ->where('status', 'open')
        ->first();

    expect($followUp)->not->toBeNull()
        ->and($followUp->due_date->format('Y-m-d'))->toBe($date)
        ->and($followUp->note)->toBe('Check back in two weeks')
        ->and($followUp->follow_up_type_id)->toBe($type->id)
        ->and($followUp->patient_id)->toBe($case->patient_id);
});

it('preserves opened_at when status changes', function (): void {
    ['owner' => $owner, 'case' => $case] = cstSetup();
    $originalOpenedAt = $case->opened_at->toDateTimeString();

    $this->actingAs($owner)
        ->patch(route('cases.status.update', $case), ['status' => 'closed']);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->opened_at->toDateTimeString())->toBe($originalOpenedAt);
});
