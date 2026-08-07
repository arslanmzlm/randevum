<?php

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Transaction;
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
});

function rbtiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("doctor, service, appointment_type and expense_owner tabs never leak another clinic's rows or totals", function (): void {
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    rbtiRole($ownerA, 'owner', $clinicA->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $serviceA = Service::factory()->create(['clinic_id' => $clinicA->id, 'vertical_id' => $clinicA->vertical_id]);
    $typeA = AppointmentType::factory()->create(['clinic_id' => $clinicA->id, 'vertical_id' => $clinicA->vertical_id]);

    $clinicB = Clinic::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $serviceB = Service::factory()->create(['clinic_id' => $clinicB->id, 'vertical_id' => $clinicB->vertical_id]);
    $typeB = AppointmentType::factory()->create(['clinic_id' => $clinicB->id, 'vertical_id' => $clinicB->vertical_id]);

    // Clinic A: one row per breakdown, small amounts.
    $apptA = Appointment::factory()->create([
        'clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id,
        'appointment_type_id' => $typeA->id, 'starts_at' => '2026-06-10 09:00:00',
    ]);
    $treatmentA = Treatment::factory()->completed()->create([
        'clinic_id' => $clinicA->id, 'appointment_id' => $apptA->id,
        'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinicA->id, 'patient_id' => $patientA->id, 'treatment_id' => $treatmentA->id,
        'amount' => '300.00', 'paid_at' => '2026-06-10 10:30:00',
    ]);
    TreatmentServiceLine::factory()->create([
        'treatment_id' => $treatmentA->id, 'service_id' => $serviceA->id,
        'quantity' => 1, 'unit_price' => '100.00', 'subtotal' => '100.00',
    ]);
    Expense::factory()->create([
        'clinic_id' => $clinicA->id, 'created_by' => $ownerA->id,
        'expense_date' => '2026-06-10', 'amount' => '20.00',
    ]);

    // Clinic B: same shape, much larger amounts — and reuses ownerA's user id as
    // created_by, so the expense_owner isolation is genuinely about clinic_id, not
    // about which user created the row.
    $apptB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'appointment_type_id' => $typeB->id, 'starts_at' => '2026-06-10 09:00:00',
    ]);
    $treatmentB = Treatment::factory()->completed()->create([
        'clinic_id' => $clinicB->id, 'appointment_id' => $apptB->id,
        'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinicB->id, 'patient_id' => $patientB->id, 'treatment_id' => $treatmentB->id,
        'amount' => '9999.00', 'paid_at' => '2026-06-10 10:30:00',
    ]);
    TreatmentServiceLine::factory()->create([
        'treatment_id' => $treatmentB->id, 'service_id' => $serviceB->id,
        'quantity' => 50, 'unit_price' => '100.00', 'subtotal' => '5000.00',
    ]);
    Expense::factory()->create([
        'clinic_id' => $clinicB->id, 'created_by' => $ownerA->id,
        'expense_date' => '2026-06-10', 'amount' => '8888.00',
    ]);

    $window = ['start' => '2026-06-01', 'end' => '2026-06-30'];

    foreach ([
        'doctor' => ['id' => $doctorA->id, 'amount' => '300.00'],
        'service' => ['id' => $serviceA->id, 'amount' => '100.00'],
        'appointment_type' => ['id' => $typeA->id, 'amount' => '300.00'],
        'expense_owner' => ['id' => $ownerA->id, 'amount' => '20.00'],
    ] as $tab => $expected) {
        $this->actingAs($ownerA)
            ->get(route('reports.index', array_merge($window, ['tab' => $tab])))
            ->assertInertia(fn ($page) => $page
                ->has('breakdown.data', 1)
                ->where('breakdown.data.0.id', $expected['id'])
                ->where('breakdown.data.0.amount', $expected['amount'])
                ->where('breakdown.totals.amount', $expected['amount'])
            );
    }
});

it("export never includes another clinic's rows", function (): void {
    Excel::fake();

    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    rbtiRole($ownerA, 'owner', $clinicA->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $apptA = Appointment::factory()->create([
        'clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id,
        'starts_at' => '2026-06-10 09:00:00',
    ]);
    $treatmentA = Treatment::factory()->completed()->create([
        'clinic_id' => $clinicA->id, 'appointment_id' => $apptA->id,
        'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinicA->id, 'patient_id' => $patientA->id, 'treatment_id' => $treatmentA->id,
        'amount' => '300.00', 'paid_at' => '2026-06-10 10:30:00',
    ]);

    $clinicB = Clinic::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $apptB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'starts_at' => '2026-06-10 09:00:00',
    ]);
    $treatmentB = Treatment::factory()->completed()->create([
        'clinic_id' => $clinicB->id, 'appointment_id' => $apptB->id,
        'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinicB->id, 'patient_id' => $patientB->id, 'treatment_id' => $treatmentB->id,
        'amount' => '9999.00', 'paid_at' => '2026-06-10 10:30:00',
    ]);

    $this->actingAs($ownerA)
        ->get(route('reports.export', ['tab' => 'doctor', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertOk();

    Excel::assertDownloaded('report-doctor-2026-06-01-2026-06-30.xlsx', function (ReportExport $export) use ($doctorA): bool {
        $rows = $export->sheets()[0]->array();

        // One data row (doctor A) plus the trailing totals row — clinic B never appears.
        expect($rows)->toHaveCount(2)
            ->and($rows[0][0])->toBe($doctorA->display_name)
            ->and($rows[0][1])->toBe(300.0);

        return true;
    });
});
