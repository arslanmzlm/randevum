<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
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

function miRole(User $user, string $role, int $clinicId): void
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
function miClinic(): array
{
    return ['clinic' => Clinic::factory()->create()];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function miPayload(array $overrides = []): array
{
    return array_merge([
        'paid_at' => '2026-06-15',
        'amount' => '1500.00',
        'payment_method' => 'cash',
        'category' => 'Kira geliri',
        'note' => 'Haziran kira',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// POST /incomes — creation persists the manual-income shape
// ---------------------------------------------------------------------------

it('any role with transactions.create can record a manual income with patient_id and treatment_id null', function (string $role): void {
    ['clinic' => $clinic] = miClinic();
    $user = User::factory()->create();
    miRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('incomes.store'), miPayload())
        ->assertRedirect();

    $income = Transaction::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->whereNull('patient_id')
        ->first();

    expect($income)->not->toBeNull()
        ->and($income->patient_id)->toBeNull()
        ->and($income->treatment_id)->toBeNull()
        ->and($income->status)->toBe(TransactionStatus::Completed)
        ->and($income->category)->toBe('Kira geliri')
        ->and($income->note)->toBe('Haziran kira')
        ->and((float) $income->amount)->toBe(1500.0)
        ->and($income->payment_method)->toBe(PaymentMethod::Cash);
})->with(['owner', 'manager', 'doctor', 'receptionist']);

it('writes a null → completed status_log for the manual income', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('incomes.store'), miPayload());

    $income = Transaction::withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereNull('patient_id')->first();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $income->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('completed');
});

// ---------------------------------------------------------------------------
// GET /incomes — lists only patient-less rows
// ---------------------------------------------------------------------------

it('index lists only manual income, never a patient payment in the same window', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'category' => 'Kira geliri',
        'paid_at' => CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'),
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'paid_at' => CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'),
    ]);

    $this->actingAs($owner)
        ->get(route('incomes.index', ['start' => '2026-06-15', 'end' => '2026-06-15']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('incomes/Index')
            ->has('incomes.data', 1)
            ->where('incomes.data.0.category', 'Kira geliri')
        );
});

it('narrows the date window across the clinic timezone boundary', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    // UTC 2026-06-10 22:00 is 2026-06-11 01:00 in Istanbul — belongs to the 11th, not the 10th.
    Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'paid_at' => CarbonImmutable::parse('2026-06-10 22:00:00', 'UTC'),
    ]);

    $this->actingAs($owner)
        ->get(route('incomes.index', ['start' => '2026-06-10', 'end' => '2026-06-10']))
        ->assertInertia(fn ($page) => $page->has('incomes.data', 0));

    $this->actingAs($owner)
        ->get(route('incomes.index', ['start' => '2026-06-11', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page->has('incomes.data', 1));
});

it('narrows by category', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'category' => 'Kira geliri']);
    Transaction::factory()->manual()->create(['clinic_id' => $clinic->id, 'category' => 'Kurs geliri']);

    $this->actingAs($owner)
        ->get(route('incomes.index', ['entire' => 1, 'category' => 'Kira geliri']))
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 1)
            ->where('incomes.data.0.category', 'Kira geliri')
        );
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('store requires amount', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['amount' => null]))
        ->assertSessionHasErrors('amount');
});

it('store rejects a zero amount', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['amount' => '0']))
        ->assertSessionHasErrors('amount');
});

it('store rejects a negative amount', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['amount' => '-10.00']))
        ->assertSessionHasErrors('amount');
});

it('store rejects an invalid payment_method', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['payment_method' => 'bitcoin']))
        ->assertSessionHasErrors('payment_method');
});

it('store rejects a future paid_at', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['paid_at' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors('paid_at');
});

it('store allows a null category and note', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('incomes.store'), miPayload(['category' => null, 'note' => null]))
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('an assistant (lacks transactions.create) gets 403 on store', function (): void {
    ['clinic' => $clinic] = miClinic();
    $assistant = User::factory()->create();
    miRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('incomes.store'), miPayload())
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('an assistant (lacks transactions.viewAny) gets 403 on the index', function (): void {
    ['clinic' => $clinic] = miClinic();
    $assistant = User::factory()->create();
    miRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('incomes.index'))
        ->assertForbidden();
});

it('includes transactions.create and transactions.viewAny in auth.permissions for owner (Gelir ekle visible)', function (): void {
    ['clinic' => $clinic] = miClinic();
    $owner = User::factory()->create();
    miRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('incomes.index'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('transactions.create') && $p->contains('transactions.viewAny'),
        ));
});
