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
 * Assign a clinic-scoped role (case-prop-contract tests).
 */
function cpcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with an owner (viewAll) and an open case, forced to the given status.
 *
 * @return array{owner: User, case: CaseRecord}
 */
function cpcCase(string $status = 'open'): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cpcRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'opened_at' => now(),
    ]);

    $updates = ['status' => $status];
    if ($status === 'suspended') {
        $updates['suspended_at'] = now();
    } elseif ($status === 'closed') {
        $updates['closed_at'] = now();
    }
    $case->updateQuietly($updates);
    $case->refresh();

    return compact('owner', 'case');
}

// ---------------------------------------------------------------------------
// allowedTransitions — the prop the frontend gates the status-action buttons on.
// Server enforcement of illegal transitions lives in CaseStatusTransitionTest;
// here we lock the *button set* contract each status hands the page.
// ---------------------------------------------------------------------------

dataset('status → allowed transitions', [
    'open offers suspend, follow-up, close' => ['open', ['suspended', 'follow_up', 'closed']],
    'suspended offers reopen and close' => ['suspended', ['open', 'closed']],
    'follow_up offers reopen and close' => ['follow_up', ['open', 'closed']],
    'closed offers reopen only' => ['closed', ['open']],
]);

it('passes the allowedTransitions matching the case status', function (string $status, array $expected): void {
    ['owner' => $owner, 'case' => $case] = cpcCase($status);

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('allowedTransitions', $expected)
        );
})->with('status → allowed transitions');

// ---------------------------------------------------------------------------
// canEditTitle — the prop the frontend gates the inline title editor on.
// The 48h server window is enforced in CaseNotesFollowUpTitleTest; here we
// lock the prop the page reads to show/hide the edit affordance.
// ---------------------------------------------------------------------------

it('passes canEditTitle=true for a freshly opened case (within the 48h window)', function (): void {
    ['owner' => $owner, 'case' => $case] = cpcCase();

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditTitle', true));
});

it('passes canEditTitle=false once the 48h window has expired', function (): void {
    ['owner' => $owner, 'case' => $case] = cpcCase();
    $case->updateQuietly(['opened_at' => Carbon::now()->subDays(3)]);

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditTitle', false));
});

// ---------------------------------------------------------------------------
// case.follow_ups / followUpTypes — the props CaseFollowUpCard reads
// (`props.caseRecord.follow_ups`, the type picker options). Locks the shape
// FollowUpService::forCase()/FollowUpTypeService::listActiveOptions() hand the
// page so a controller-side rename or dropped field breaks a test, not the UI.
// ---------------------------------------------------------------------------

it('passes case.follow_ups with the documented shape for an open and a done follow-up', function (): void {
    ['owner' => $owner, 'case' => $case] = cpcCase();

    $type = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id]);

    $open = FollowUp::factory()->open()->create([
        'clinic_id' => $case->clinic_id,
        'patient_id' => $case->patient_id,
        'case_id' => $case->id,
        'follow_up_type_id' => $type->id,
        'due_date' => now()->addDays(2)->toDateString(),
    ]);

    $done = FollowUp::factory()->done()->create([
        'clinic_id' => $case->clinic_id,
        'patient_id' => $case->patient_id,
        'case_id' => $case->id,
        'follow_up_type_id' => null,
        'completed_by_user_id' => $owner->id,
        'result_note' => 'Hasta kontrole geldi.',
    ]);

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('case.follow_ups', 2)
            ->where('case.follow_ups.0', [
                'id' => $open->id,
                'status' => 'open',
                'type' => ['id' => $type->id, 'name' => $type->name],
                'due_date' => $open->due_date->format('Y-m-d'),
                'note' => $open->note,
                'completed_at' => null,
                'completed_by' => null,
                'result_note' => null,
                'is_overdue' => false,
            ])
            ->where('case.follow_ups.1', [
                'id' => $done->id,
                'status' => 'done',
                'type' => null,
                'due_date' => $done->due_date->format('Y-m-d'),
                'note' => $done->note,
                'completed_at' => $done->completed_at->toIso8601String(),
                'completed_by' => $owner->name,
                'result_note' => 'Hasta kontrole geldi.',
                'is_overdue' => false,
            ])
        );
});

it('passes case.follow_ups.*.is_overdue=true for an open follow-up past its due date', function (): void {
    ['owner' => $owner, 'case' => $case] = cpcCase();

    $overdue = FollowUp::factory()->open()->overdue()->create([
        'clinic_id' => $case->clinic_id,
        'patient_id' => $case->patient_id,
        'case_id' => $case->id,
    ]);

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('case.follow_ups.0.id', $overdue->id)
            ->where('case.follow_ups.0.is_overdue', true)
        );
});

it('passes followUpTypes with only the clinic\'s active types', function (): void {
    ['owner' => $owner, 'case' => $case] = cpcCase();

    $active = FollowUpType::factory()->create(['clinic_id' => $case->clinic_id, 'name' => 'Kontrol']);
    FollowUpType::factory()->inactive()->create(['clinic_id' => $case->clinic_id]);
    FollowUpType::factory()->create(); // another clinic's type

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUpTypes', 1)
            ->where('followUpTypes.0', ['id' => $active->id, 'name' => $active->name])
        );
});
