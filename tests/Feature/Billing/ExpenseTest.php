<?php

use App\Models\Clinic;
use App\Models\Expense;
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

function exRole(User $user, string $role, int $clinicId): void
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
function exClinic(): array
{
    return ['clinic' => Clinic::factory()->create()];
}

/**
 * @return array<string, mixed>
 */
function exStorePayload(array $overrides = []): array
{
    return array_merge([
        'expense_date' => '2026-06-15',
        'amount' => '120.50',
        'category' => 'Kira',
        'description' => 'Haziran kirası',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// POST /expenses — any clinic role can create; created_by = actor
// ---------------------------------------------------------------------------

it('any clinic role can record an expense with created_by set to the actor', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $user = User::factory()->create();
    exRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('expenses.store'), exStorePayload())
        ->assertRedirect();

    $expense = Expense::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->first();

    expect($expense)->not->toBeNull()
        ->and($expense->created_by)->toBe($user->id)
        ->and($expense->clinic_id)->toBe($clinic->id)
        ->and($expense->amount)->toEqual('120.50')
        ->and($expense->category)->toBe('Kira');
})->with(['owner', 'manager', 'doctor', 'receptionist', 'assistant']);

it('successful store flashes a success toast', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload())
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// POST /expenses — validation
// ---------------------------------------------------------------------------

it('store requires expense_date', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['expense_date' => null]))
        ->assertSessionHasErrors('expense_date');
});

it('store rejects an expense_date not in Y-m-d format', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['expense_date' => '15/06/2026']))
        ->assertSessionHasErrors('expense_date');
});

it('store requires amount', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['amount' => null]))
        ->assertSessionHasErrors('amount');
});

it('store rejects a zero amount', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['amount' => '0']))
        ->assertSessionHasErrors('amount');
});

it('store rejects an amount with more than 2 decimal places', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['amount' => '10.123']))
        ->assertSessionHasErrors('amount');
});

it('store rejects a category longer than 100 characters', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['category' => str_repeat('a', 101)]))
        ->assertSessionHasErrors('category');
});

it('store allows a null category and description (both nullable)', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['category' => null, 'description' => null]))
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

it('store rejects a description longer than 1000 characters', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('expenses.store'), exStorePayload(['description' => str_repeat('a', 1001)]))
        ->assertSessionHasErrors('description');
});

// ---------------------------------------------------------------------------
// GET /expenses — Giderlerim, own-only list
// ---------------------------------------------------------------------------

it('Giderlerim is reachable by every clinic role via the create permission alone', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $user = User::factory()->create();
    exRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('expenses/Index')
            ->has('expenses')
            ->has('categories')
            ->has('filters')
            ->has('query')
            ->has('currency')
            ->missing('revenue')
            ->missing('net')
        );
})->with(['owner', 'manager', 'doctor', 'receptionist', 'assistant']);

// ---------------------------------------------------------------------------
// auth.permissions — the exact ability the frontend gates on (useCan()),
// never a role-name check. ExpenseList shows the "any" edit/delete + creator
// column when expenses.viewAny is present; own-row control uses created_by.
// ---------------------------------------------------------------------------

it('auth.permissions includes expenses.viewAny for owner and manager', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $user = User::factory()->create();
    exRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('expenses.viewAny')));
})->with(['owner', 'manager']);

it('auth.permissions excludes expenses.viewAny for doctor, receptionist, and assistant', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $user = User::factory()->create();
    exRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('expenses.viewAny')));
})->with(['doctor', 'receptionist', 'assistant']);

it('auth.permissions includes expenses.create for every clinic role', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $user = User::factory()->create();
    exRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('expenses.create')));
})->with(['owner', 'manager', 'doctor', 'receptionist', 'assistant']);

it("Giderlerim never lists another staff member's expenses", function (): void {
    ['clinic' => $clinic] = exClinic();
    $assistant = User::factory()->create();
    exRole($assistant, 'assistant', $clinic->id);
    $otherStaff = User::factory()->create();
    exRole($otherStaff, 'receptionist', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $assistant->id]);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id]);

    // entire=1 bypasses the default "this month" window — the factory's random expense_date
    // isn't pinned, so this isolates the assertion to the ownership filter being tested.
    $this->actingAs($assistant)
        ->get(route('expenses.index', ['entire' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('expenses.data', 1)
            ->where('expenses.data.0.created_by', $assistant->id)
        );
});

it('Giderlerim filters the own list by category and date range', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id, 'expense_date' => '2026-06-10', 'category' => 'Kira']);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id, 'expense_date' => '2026-06-11', 'category' => 'Fatura']);

    $this->actingAs($owner)
        ->get(route('expenses.index', ['start' => '2026-06-10', 'end' => '2026-06-10', 'category' => 'Kira']))
        ->assertInertia(fn ($page) => $page->has('expenses.data', 1)
            ->where('expenses.data.0.category', 'Kira'));
});

// ---------------------------------------------------------------------------
// Scope switch — own vs whole clinic (the finance report links here for the latter)
// ---------------------------------------------------------------------------

it('shows the whole clinic to a viewer holding expenses.viewAny by default', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $viewer = User::factory()->create();
    exRole($viewer, $role, $clinic->id);
    $otherStaff = User::factory()->create();
    exRole($otherStaff, 'receptionist', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $viewer->id]);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id]);

    $this->actingAs($viewer)
        ->get(route('expenses.index', ['entire' => 1]))
        ->assertInertia(fn ($page) => $page
            ->has('expenses.data', 2)
            ->where('canViewAll', true)
            ->where('scope', 'all')
        );
})->with(['owner', 'manager']);

it('narrows back to own rows when scope=own', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);
    $otherStaff = User::factory()->create();
    exRole($otherStaff, 'receptionist', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id]);

    $this->actingAs($owner)
        ->get(route('expenses.index', ['entire' => 1, 'scope' => 'own']))
        ->assertInertia(fn ($page) => $page
            ->has('expenses.data', 1)
            ->where('expenses.data.0.created_by', $owner->id)
            ->where('scope', 'own')
        );
});

it('ignores scope=all for a role without expenses.viewAny', function (string $role): void {
    ['clinic' => $clinic] = exClinic();
    $staff = User::factory()->create();
    exRole($staff, $role, $clinic->id);
    $otherStaff = User::factory()->create();
    exRole($otherStaff, 'manager', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $staff->id]);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id]);

    $this->actingAs($staff)
        ->get(route('expenses.index', ['entire' => 1, 'scope' => 'all']))
        ->assertInertia(fn ($page) => $page
            ->has('expenses.data', 1)
            ->where('expenses.data.0.created_by', $staff->id)
            ->where('canViewAll', false)
            ->where('scope', 'own')
        );
})->with(['doctor', 'receptionist', 'assistant']);

it("never lists another clinic's expenses in the clinic-wide scope", function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);

    $otherClinic = Clinic::factory()->create();
    $otherOwner = User::factory()->create();
    exRole($otherOwner, 'owner', $otherClinic->id);
    Expense::factory()->create(['clinic_id' => $otherClinic->id, 'created_by' => $otherOwner->id]);

    $this->actingAs($owner)
        ->get(route('expenses.index', ['entire' => 1]))
        ->assertInertia(fn ($page) => $page->has('expenses.data', 1));
});

// ---------------------------------------------------------------------------
// PUT / DELETE /expenses/{expense} — ownership
// ---------------------------------------------------------------------------

it('a staff member can update their own expense', function (): void {
    ['clinic' => $clinic] = exClinic();
    $receptionist = User::factory()->create();
    exRole($receptionist, 'receptionist', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $receptionist->id, 'amount' => '10.00']);

    $this->actingAs($receptionist)
        ->put(route('expenses.update', $expense), exStorePayload(['amount' => '77.00']))
        ->assertRedirect();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->amount)->toEqual('77.00');
});

it("a staff member gets 403 updating another user's expense", function (): void {
    ['clinic' => $clinic] = exClinic();
    $receptionist = User::factory()->create();
    $otherStaff = User::factory()->create();
    exRole($receptionist, 'receptionist', $clinic->id);
    exRole($otherStaff, 'assistant', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id, 'amount' => '10.00']);

    $this->actingAs($receptionist)
        ->put(route('expenses.update', $expense), exStorePayload(['amount' => '77.00']))
        ->assertForbidden();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->amount)->toEqual('10.00');
});

it("owner can update any user's expense", function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);
    exRole($staff, 'assistant', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $staff->id, 'amount' => '10.00']);

    $this->actingAs($owner)
        ->put(route('expenses.update', $expense), exStorePayload(['amount' => '99.00']))
        ->assertRedirect();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->amount)->toEqual('99.00');
});

it("manager can delete any user's expense", function (): void {
    ['clinic' => $clinic] = exClinic();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    exRole($manager, 'manager', $clinic->id);
    exRole($staff, 'doctor', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $staff->id]);

    $this->actingAs($manager)
        ->delete(route('expenses.destroy', $expense))
        ->assertRedirect();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->deleted_at)->not->toBeNull();
});

it('a staff member can delete their own expense', function (): void {
    ['clinic' => $clinic] = exClinic();
    $assistant = User::factory()->create();
    exRole($assistant, 'assistant', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $assistant->id]);

    $this->actingAs($assistant)
        ->delete(route('expenses.destroy', $expense))
        ->assertRedirect();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->deleted_at)->not->toBeNull();
});

it("a staff member gets 403 deleting another user's expense", function (): void {
    ['clinic' => $clinic] = exClinic();
    $assistant = User::factory()->create();
    $otherStaff = User::factory()->create();
    exRole($assistant, 'assistant', $clinic->id);
    exRole($otherStaff, 'receptionist', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $otherStaff->id]);

    $this->actingAs($assistant)
        ->delete(route('expenses.destroy', $expense))
        ->assertForbidden();

    expect(Expense::withoutGlobalScopes()->find($expense->id)->deleted_at)->toBeNull();
});

it('successful update and delete each flash a success toast', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);
    $expense = Expense::factory()->create(['clinic_id' => $clinic->id, 'created_by' => $owner->id]);

    $this->actingAs($owner)
        ->put(route('expenses.update', $expense), exStorePayload())
        ->assertSessionHas('toasts');

    $this->actingAs($owner)
        ->delete(route('expenses.destroy', $expense))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Category suggestions — clinic-scoped, deduplicated
// ---------------------------------------------------------------------------

it('category suggestions are deduplicated and clinic-scoped', function (): void {
    ['clinic' => $clinic] = exClinic();
    $owner = User::factory()->create();
    exRole($owner, 'owner', $clinic->id);

    Expense::factory()->create(['clinic_id' => $clinic->id, 'category' => 'Kira']);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'category' => 'Kira']);
    Expense::factory()->create(['clinic_id' => $clinic->id, 'category' => 'Fatura']);

    $otherClinic = Clinic::factory()->create();
    Expense::factory()->create(['clinic_id' => $otherClinic->id, 'category' => 'Personel']);

    $this->actingAs($owner)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page
            ->where('categories', fn ($categories) => collect($categories)->count() === 2
                && collect($categories)->contains('Kira')
                && collect($categories)->contains('Fatura')
                && ! collect($categories)->contains('Personel'))
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 updating clinic B's expense", function (): void {
    ['clinic' => $clinicA] = exClinic();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    exRole($ownerA, 'owner', $clinicA->id);

    $expenseB = Expense::factory()->create(['clinic_id' => $clinicB->id, 'amount' => '10.00']);

    $this->actingAs($ownerA)
        ->put(route('expenses.update', $expenseB), exStorePayload())
        ->assertNotFound();

    expect(Expense::withoutGlobalScopes()->find($expenseB->id)->amount)->toEqual('10.00');
});

it("clinic A owner gets 404 deleting clinic B's expense", function (): void {
    ['clinic' => $clinicA] = exClinic();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    exRole($ownerA, 'owner', $clinicA->id);

    $expenseB = Expense::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->delete(route('expenses.destroy', $expenseB))
        ->assertNotFound();

    expect(Expense::withoutGlobalScopes()->find($expenseB->id)->deleted_at)->toBeNull();
});

it("Giderlerim never exposes another clinic's expense even when created_by matches the same user id", function (): void {
    ['clinic' => $clinicA] = exClinic();
    $clinicB = Clinic::factory()->create();
    $user = User::factory()->create();
    exRole($user, 'owner', $clinicA->id);

    // Same user id recorded an expense while scoped to clinic B (e.g. a former membership) —
    // ClinicScope must still hide it from clinic A's own-list.
    Expense::factory()->create(['clinic_id' => $clinicB->id, 'created_by' => $user->id]);

    $this->actingAs($user)
        ->get(route('expenses.index'))
        ->assertInertia(fn ($page) => $page->has('expenses.data', 0));
});
