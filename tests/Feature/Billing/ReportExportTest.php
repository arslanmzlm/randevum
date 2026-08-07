<?php

use App\Models\Clinic;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\TreatmentServiceLine;
use App\Models\User;
use App\Modules\Billing\Exports\ReportExport;
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
    Excel::fake();
});

function reRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('downloads a single tab as one xlsx sheet named after the date window', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    reRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('reports.export', ['tab' => 'doctor', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertOk();

    Excel::assertDownloaded('report-doctor-2026-06-01-2026-06-30.xlsx', function (ReportExport $export): bool {
        expect($export->sheets())->toHaveCount(1);

        return true;
    });
});

it('tab=all downloads a 6-sheet workbook (finance + every breakdown)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    reRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('reports.export', ['tab' => 'all', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertOk();

    Excel::assertDownloaded('report-all-2026-06-01-2026-06-30.xlsx', function (ReportExport $export): bool {
        expect($export->sheets())->toHaveCount(6);

        return true;
    });
});

it('export ignores pagination and writes every row for the window', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    reRole($owner, 'owner', $clinic->id);

    foreach (['A', 'B', 'C'] as $name) {
        $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => $name]);
        $treatment = Treatment::factory()->completed()->create(['clinic_id' => $clinic->id, 'completed_at' => '2026-06-10 10:00:00']);
        TreatmentServiceLine::factory()->create([
            'treatment_id' => $treatment->id, 'service_id' => $service->id,
            'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00',
        ]);
    }

    $this->actingAs($owner)
        ->get(route('reports.export', [
            'tab' => 'service', 'start' => '2026-06-01', 'end' => '2026-06-30', 'per_page' => 1,
        ]))
        ->assertOk();

    Excel::assertDownloaded('report-service-2026-06-01-2026-06-30.xlsx', function (ReportExport $export): bool {
        $rows = $export->sheets()[0]->array();

        // 3 data rows + 1 totals row, despite per_page=1.
        expect($rows)->toHaveCount(4);

        return true;
    });
});

it('forbids a receptionist from exporting', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    reRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('reports.export', ['tab' => 'doctor']))
        ->assertForbidden();
});
