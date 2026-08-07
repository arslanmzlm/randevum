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

function mitRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("clinic A's income index never returns clinic B's manual income", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    mitRole($ownerA, 'owner', $clinicA->id);
    Transaction::factory()->manual()->create(['clinic_id' => $clinicA->id, 'category' => 'A geliri']);

    $clinicB = Clinic::factory()->create();
    Transaction::factory()->manual()->create(['clinic_id' => $clinicB->id, 'category' => 'B geliri']);

    $this->actingAs($ownerA)
        ->get(route('incomes.index', ['entire' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 1)
            ->where('incomes.data.0.category', 'A geliri')
        );
});

it("clinic A cannot delete clinic B's manual income (404, scoped route binding)", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    mitRole($ownerA, 'owner', $clinicA->id);

    $clinicB = Clinic::factory()->create();
    $incomeB = Transaction::factory()->manual()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->delete(route('incomes.destroy', $incomeB))
        ->assertNotFound();

    expect(Transaction::withoutGlobalScopes()->find($incomeB->id))->not->toBeNull();
});

it("a manual income created while clinic A is active carries clinic A's clinic_id", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    mitRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('incomes.store'), [
            'paid_at' => '2026-06-15',
            'amount' => '250.00',
            'payment_method' => 'cash',
            'category' => 'Kira geliri',
        ])
        ->assertRedirect();

    $income = Transaction::withoutGlobalScopes()->where('category', 'Kira geliri')->first();

    expect($income)->not->toBeNull()
        ->and($income->clinic_id)->toBe($clinicA->id);
});

it("clinic A's category suggestions never include clinic B's manual-income categories", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    mitRole($ownerA, 'owner', $clinicA->id);
    Transaction::factory()->manual()->create(['clinic_id' => $clinicA->id, 'category' => 'Kira geliri']);

    $clinicB = Clinic::factory()->create();
    Transaction::factory()->manual()->create(['clinic_id' => $clinicB->id, 'category' => 'Kurs geliri']);

    $this->actingAs($ownerA)
        ->get(route('incomes.index'))
        ->assertInertia(fn ($page) => $page->where(
            'categories',
            fn ($categories) => collect($categories)->contains('Kira geliri') && ! collect($categories)->contains('Kurs geliri'),
        ));
});

it("clinic B's narrowed (no reports.revenue) doctor view never returns clinic A's manual income, even one created by a same-permission role", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    mitRole($ownerA, 'owner', $clinicA->id);
    Transaction::factory()->manual()->create([
        'clinic_id' => $clinicA->id,
        'category' => 'A geliri',
        'created_by' => $ownerA->id,
    ]);

    $clinicB = Clinic::factory()->create();
    $doctorB = User::factory()->create();
    mitRole($doctorB, 'doctor', $clinicB->id);
    $incomeB = Transaction::factory()->manual()->create([
        'clinic_id' => $clinicB->id,
        'category' => 'B geliri',
        'created_by' => $doctorB->id,
    ]);

    $this->actingAs($doctorB)
        ->get(route('incomes.index', ['entire' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 1)
            ->where('incomes.data.0.id', $incomeB->id)
            ->where('categories', ['B geliri'])
        );
});
