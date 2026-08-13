<?php

use App\Models\Clinic;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Reporting\Exports\ReportExport;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function rbtRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('tab=branch returns one row per tenant branch with amount/count/average/expense/net', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Şube A']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'name' => 'Şube B']);
    $owner = User::factory()->create();
    rbtRole($owner, 'owner', $clinicA->id);
    rbtRole($owner, 'owner', $clinicB->id);

    Transaction::factory()->create(['clinic_id' => $clinicA->id, 'amount' => '300.00', 'paid_at' => '2026-06-10 10:00:00']);
    Expense::factory()->create(['clinic_id' => $clinicA->id, 'expense_date' => '2026-06-10', 'amount' => '50.00']);

    Transaction::factory()->create(['clinic_id' => $clinicB->id, 'amount' => '900.00', 'paid_at' => '2026-06-11 10:00:00']);
    Expense::factory()->create(['clinic_id' => $clinicB->id, 'expense_date' => '2026-06-11', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'branch', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->where('multiBranch', true)
            ->has('breakdown.data', 2)
            ->where('breakdown.data', function ($rows) use ($clinicA, $clinicB) {
                $byId = collect($rows)->keyBy('id');
                $rowA = $byId[$clinicA->id];
                $rowB = $byId[$clinicB->id];

                return $rowA['amount'] === '300.00' && $rowA['count'] === 1
                    && $rowA['average'] === '300.00' && $rowA['expense'] === '50.00'
                    && $rowA['net'] === '250.00'
                    && $rowB['amount'] === '900.00' && $rowB['expense'] === '100.00'
                    && $rowB['net'] === '800.00';
            })
            ->where('breakdown.totals.amount', '1200.00')
            ->where('breakdown.totals.count', 2)
        );
});

it('a single-branch user gets multiBranch === false and tab=branch falls back to finance', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rbtRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'branch']))
        ->assertInertia(fn ($page) => $page
            ->where('multiBranch', false)
            ->where('tab', 'finance')
            ->where('breakdown', null)
        );
});

it('tab=branch export produces a sheet with the branch column set', function (): void {
    Excel::fake();

    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id]);
    $owner = User::factory()->create();
    rbtRole($owner, 'owner', $clinicA->id);
    rbtRole($owner, 'owner', $clinicB->id);

    Transaction::factory()->create(['clinic_id' => $clinicA->id, 'amount' => '300.00', 'paid_at' => '2026-06-10 10:00:00']);
    Transaction::factory()->create(['clinic_id' => $clinicB->id, 'amount' => '900.00', 'paid_at' => '2026-06-11 10:00:00']);

    $this->actingAs($owner)
        ->get(route('reports.export', ['tab' => 'branch', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertOk();

    Excel::assertDownloaded('report-branch-2026-06-01-2026-06-30.xlsx', function (ReportExport $export): bool {
        $rows = $export->sheets()[0]->array();

        // 2 branch rows + 1 totals row.
        expect($rows)->toHaveCount(3);

        return true;
    });
});

it("isolation: another tenant's figures never enter the branch totals, and a same-tenant branch the user holds no role in is excluded", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id]); // same tenant, owner has role
    $clinicC = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id]); // same tenant, owner has NO role
    $foreignClinic = Clinic::factory()->create(); // different tenant entirely

    $owner = User::factory()->create();
    rbtRole($owner, 'owner', $clinicA->id);
    rbtRole($owner, 'owner', $clinicB->id);

    Transaction::factory()->create(['clinic_id' => $clinicA->id, 'amount' => '100.00', 'paid_at' => '2026-06-10 10:00:00']);
    Transaction::factory()->create(['clinic_id' => $clinicB->id, 'amount' => '200.00', 'paid_at' => '2026-06-10 10:00:00']);
    Transaction::factory()->create(['clinic_id' => $clinicC->id, 'amount' => '99999.00', 'paid_at' => '2026-06-10 10:00:00']);
    Transaction::factory()->create(['clinic_id' => $foreignClinic->id, 'amount' => '88888.00', 'paid_at' => '2026-06-10 10:00:00']);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'branch', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 2)
            ->where('breakdown.totals.amount', '300.00')
        );
});

it('forbids a receptionist from the branch tab', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    rbtRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('reports.index', ['tab' => 'branch']))
        ->assertForbidden();
});
