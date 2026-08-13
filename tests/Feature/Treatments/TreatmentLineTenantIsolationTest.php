<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\TreatmentProductLine;
use App\Models\TreatmentServiceLine;
use App\Models\User;
use App\Scopes\ClinicScope;
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
 * Clinic + owner + doctor + patient + arrived appointment + draft treatment.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, treatment: Treatment}
 */
function tltiSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles');
    $owner->unsetRelation('permissions');

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->subHour();
    $appointment = Appointment::factory()
        ->withStatus(AppointmentStatus::Arrived)
        ->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
        ]);

    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'treatment');
}

it('stamps the treatment clinic on service and product lines written by the complete flow', function (): void {
    $setup = tltiSetup();
    $service = Service::factory()->create(['clinic_id' => $setup['clinic']->id, 'price' => '100.00']);
    $product = Product::factory()->create(['clinic_id' => $setup['clinic']->id, 'current_stock' => 10]);

    $this->actingAs($setup['owner'])
        ->put(route('treatments.complete', $setup['treatment']), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
            'services' => [['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '100.00']],
            'products' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '25.00']],
        ])
        ->assertRedirect();

    $serviceLine = TreatmentServiceLine::withoutGlobalScope(ClinicScope::class)
        ->where('treatment_id', $setup['treatment']->id)->sole();
    $productLine = TreatmentProductLine::withoutGlobalScope(ClinicScope::class)
        ->where('treatment_id', $setup['treatment']->id)->sole();

    expect($serviceLine->clinic_id)->toBe($setup['clinic']->id)
        ->and($productLine->clinic_id)->toBe($setup['clinic']->id);
});

it("never returns another clinic's treatment lines", function (): void {
    $setupA = tltiSetup();
    $setupB = tltiSetup();

    $lineA = TreatmentServiceLine::factory()->create([
        'treatment_id' => $setupA['treatment']->id,
        'service_id' => Service::factory()->create(['clinic_id' => $setupA['clinic']->id])->id,
    ]);
    $lineB = TreatmentProductLine::factory()->create([
        'treatment_id' => $setupB['treatment']->id,
        'product_id' => Product::factory()->create(['clinic_id' => $setupB['clinic']->id])->id,
    ]);
    $serviceLineB = TreatmentServiceLine::factory()->create([
        'treatment_id' => $setupB['treatment']->id,
        'service_id' => Service::factory()->create(['clinic_id' => $setupB['clinic']->id])->id,
    ]);

    expect($lineA->clinic_id)->toBe($setupA['clinic']->id)
        ->and($lineB->clinic_id)->toBe($setupB['clinic']->id);

    app(ClinicContext::class)->set($setupA['clinic']->id);

    expect(TreatmentServiceLine::pluck('id')->all())->toBe([$lineA->id])
        ->and(TreatmentServiceLine::find($serviceLineB->id))->toBeNull()
        ->and(TreatmentProductLine::count())->toBe(0)
        ->and(TreatmentProductLine::find($lineB->id))->toBeNull();
});

it('returns no treatment lines at all when there is no active clinic', function (): void {
    $setup = tltiSetup();

    TreatmentServiceLine::factory()->create([
        'treatment_id' => $setup['treatment']->id,
        'service_id' => Service::factory()->create(['clinic_id' => $setup['clinic']->id])->id,
    ]);
    TreatmentProductLine::factory()->create([
        'treatment_id' => $setup['treatment']->id,
        'product_id' => Product::factory()->create(['clinic_id' => $setup['clinic']->id])->id,
    ]);

    app(ClinicContext::class)->forget();

    expect(TreatmentServiceLine::count())->toBe(0)
        ->and(TreatmentProductLine::count())->toBe(0)
        ->and(TreatmentServiceLine::withoutGlobalScope(ClinicScope::class)->count())->toBe(1)
        ->and(TreatmentProductLine::withoutGlobalScope(ClinicScope::class)->count())->toBe(1);
});
