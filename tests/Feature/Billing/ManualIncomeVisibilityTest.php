<?php

use App\Models\Clinic;
use App\Models\Transaction;
use App\Models\User;
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

function mivRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * One clinic, an owner and a doctor, each having recorded their own manual income row with a
 * distinct amount/category — used to prove the narrowed view never leaks the other's row.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, ownerIncome: Transaction, doctorIncome: Transaction}
 */
function mivSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    mivRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    mivRole($doctorUser, 'doctor', $clinic->id);

    $ownerIncome = Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => '500.00',
        'category' => 'Sahibinin geliri',
        'created_by' => $owner->id,
    ]);
    $doctorIncome = Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => '300.00',
        'category' => 'Doktorun geliri',
        'created_by' => $doctorUser->id,
    ]);

    return compact('clinic', 'owner', 'doctorUser', 'ownerIncome', 'doctorIncome');
}

// ---------------------------------------------------------------------------
// reports.revenue holder (owner) sees the whole clinic
// ---------------------------------------------------------------------------

it('owner (holds reports.revenue) sees every manual income row and every category', function (): void {
    ['owner' => $owner] = mivSetup();

    $this->actingAs($owner)
        ->get(route('incomes.index', ['entire' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 2)
            ->where(
                'categories',
                fn ($categories) => collect($categories)->contains('Sahibinin geliri')
                    && collect($categories)->contains('Doktorun geliri'),
            )
        );
});

// ---------------------------------------------------------------------------
// transactions.viewAny without reports.revenue (doctor) is narrowed to own rows
// ---------------------------------------------------------------------------

it('a doctor (transactions.viewAny, no reports.revenue) sees only their own manual income row and category', function (): void {
    ['doctorUser' => $doctorUser, 'doctorIncome' => $doctorIncome] = mivSetup();

    $this->actingAs($doctorUser)
        ->get(route('incomes.index', ['entire' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 1)
            ->where('incomes.data.0.id', $doctorIncome->id)
            ->where('incomes.data.0.category', 'Doktorun geliri')
            ->where('categories', ['Doktorun geliri'])
        );
});

it("a doctor's narrowed /incomes response never contains the owner's amount or category anywhere in the rendered payload", function (): void {
    ['doctorUser' => $doctorUser] = mivSetup();

    // Full page load (not an X-Inertia XHR) — the Inertia page JSON is embedded in the
    // rendered HTML's data-page attribute either way, so this still checks the whole payload.
    $response = $this->actingAs($doctorUser)->get(route('incomes.index', ['entire' => 1]));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->not->toContain('500.00')
        ->and($html)->not->toContain('Sahibinin geliri');
});
