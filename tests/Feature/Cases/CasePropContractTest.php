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
    } elseif ($status === 'follow_up') {
        $updates['follow_up_date'] = today()->addWeek()->format('Y-m-d');
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
