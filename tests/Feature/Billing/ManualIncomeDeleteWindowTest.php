<?php

use App\Models\Clinic;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Services\RefundService;
use App\Support\ClinicContext;
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

function midRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic}
 */
function midClinic(): array
{
    return ['clinic' => Clinic::factory()->create()];
}

// ---------------------------------------------------------------------------
// Delete inside the window
// ---------------------------------------------------------------------------

it('the recorder can delete their own manual income inside the window, removing the row and its status logs', function (): void {
    ['clinic' => $clinic] = midClinic();
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);
    StatusLog::withoutGlobalScopes()->create([
        'clinic_id' => $clinic->id,
        'loggable_type' => 'transaction',
        'loggable_id' => $income->id,
        'from_status' => null,
        'to_status' => 'completed',
        'transitioned_at' => now(),
        'by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $income))
        ->assertRedirect();

    expect(Transaction::withoutGlobalScopes()->find($income->id))->toBeNull()
        ->and(StatusLog::withoutGlobalScopes()->where('loggable_type', 'transaction')->where('loggable_id', $income->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Delete after the window
// ---------------------------------------------------------------------------

it('refuses a delete after the immutability window has passed (policy denies before the service runs)', function (): void {
    ['clinic' => $clinic] = midClinic();
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);
    // created_at isn't fillable (immutability convention) — force it back to simulate an old row.
    $income->forceFill(['created_at' => now()->subSeconds(config('platform.edit_windows.transaction_delete') + 60)])->save();

    // TransactionPolicy::delete() encodes the same window guard, so the HTTP path 403s at
    // authorize() before ManualIncomeService::delete()'s own re-guard is ever reached.
    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $income))
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($income->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Ownership — a non-creator without transactions.refund is refused
// ---------------------------------------------------------------------------

it("a non-creator without transactions.refund cannot delete another user's manual income", function (): void {
    ['clinic' => $clinic] = midClinic();
    $creator = User::factory()->create();
    midRole($creator, 'receptionist', $clinic->id);
    $otherStaff = User::factory()->create();
    midRole($otherStaff, 'manager', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $creator->id]);

    $this->actingAs($otherStaff)
        ->delete(route('incomes.destroy', $income))
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($income->id))->not->toBeNull();
});

it("owner (has transactions.refund) can delete another user's manual income", function (): void {
    ['clinic' => $clinic] = midClinic();
    $receptionist = User::factory()->create();
    midRole($receptionist, 'receptionist', $clinic->id);
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $receptionist->id]);

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $income))
        ->assertRedirect();

    expect(Transaction::withoutGlobalScopes()->find($income->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// A patient payment can never be reached through /incomes/{id}
// ---------------------------------------------------------------------------

it('a patient payment cannot be deleted through /incomes/{id}', function (): void {
    ['clinic' => $clinic] = midClinic();
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $patientPayment = Transaction::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $patientPayment))
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($patientPayment->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Refunded originals and their counter-entries are never hard-deletable
// ---------------------------------------------------------------------------

it('refuses to delete a refunded manual income original (the counter-entry would orphan)', function (): void {
    ['clinic' => $clinic] = midClinic();
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id, 'amount' => '300.00']);
    app(ClinicContext::class)->set($clinic->id);
    app(RefundService::class)->refund($income, ['amount' => '300.00', 'reason' => 'test'], $owner);
    app(ClinicContext::class)->forget();
    $income->refresh();

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $income))
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($income->id))->not->toBeNull();
});

it('refuses to delete a refund counter-entry (the refund must never be hard-deleted)', function (): void {
    ['clinic' => $clinic] = midClinic();
    $owner = User::factory()->create();
    midRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id, 'amount' => '300.00']);
    app(ClinicContext::class)->set($clinic->id);
    $counterEntry = app(RefundService::class)->refund($income, ['amount' => '300.00', 'reason' => 'test'], $owner);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $counterEntry))
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->find($counterEntry->id))->not->toBeNull();
});
