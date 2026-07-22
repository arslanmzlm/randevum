<?php

use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\TreatmentProductLine;
use App\Models\TreatmentServiceLine;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Pulls the single PDF the fake recorded via toResponse() (the controller returns the
 * PdfBuilder directly as a Responsable — it never calls ->save(), so assertSee()/
 * assertViewIs() (which only inspect savedPdfs) don't apply; assert on the captured
 * builder's public viewName/viewData/getHtml() instead).
 */
function trCapturedPdf(FakePdfBuilder $fake): PdfBuilder
{
    $captured = null;

    $fake->assertRespondedWithPdf(function ($pdf) use (&$captured): bool {
        $captured = $pdf;

        return true;
    });

    expect($captured)->not->toBeNull();

    return $captured;
}

/**
 * Assign a clinic-scoped role (treatment-report tests).
 */
function trRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Full clinic setup: one user per role + a doctor profile + a patient + a Completed
 * treatment (service line + product line + podiatry clinical fields) with two
 * transactions summing to less than the total, so the report's derived paid/balance
 * figures prove real aggregation, not a single-row echo.
 *
 * @return array{clinic: Clinic, owner: User, manager: User, receptionist: User,
 *     assistant: User, doctorUser: User, doctor: Doctor, patient: Patient, treatment: Treatment,
 *     service: Service, product: Product}
 */
function trSetup(): array
{
    $clinic = Clinic::factory()->create();

    $owner = User::factory()->create();
    trRole($owner, 'owner', $clinic->id);

    $manager = User::factory()->create();
    trRole($manager, 'manager', $clinic->id);

    $receptionist = User::factory()->create();
    trRole($receptionist, 'receptionist', $clinic->id);

    $assistant = User::factory()->create();
    trRole($assistant, 'assistant', $clinic->id);

    $doctorUser = User::factory()->create();
    trRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
    ]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Tırnak Bakımı']);
    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Nasır Kremi']);

    $detail = PodiatryTreatmentDetail::create([
        'complaint' => 'Sol ayak başparmağında ağrı',
        'diagnosis' => 'Batık tırnak',
        'treatment_process' => 'Kısmi tırnak eksizyonu uygulandı',
    ]);

    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'details_id' => $detail->id,
        'notes' => 'Kontrol 2 hafta sonra.',
        'subtotal_amount' => '300.00',
        'discount_amount' => '0.00',
        'total_amount' => '300.00',
    ]);

    TreatmentServiceLine::factory()->create([
        'treatment_id' => $treatment->id,
        'service_id' => $service->id,
        'quantity' => 1,
        'unit_price' => '200.00',
        'discount_amount' => '0.00',
        'subtotal' => '200.00',
    ]);

    TreatmentProductLine::factory()->create([
        'treatment_id' => $treatment->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => '100.00',
        'discount_amount' => '0.00',
        'subtotal' => '100.00',
    ]);

    // Two transactions (120 + 80 = 200 paid of 300 total) — proves the service derives
    // paidTotal/remainingBalance by summing through BalanceReaderContract, not echoing one row.
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '120.00',
        'payment_method' => PaymentMethod::Cash,
        'paid_at' => now()->subDay(),
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '80.00',
        'payment_method' => PaymentMethod::Card,
        'paid_at' => now(),
    ]);

    return compact('clinic', 'owner', 'manager', 'receptionist', 'assistant', 'doctorUser', 'doctor', 'patient', 'treatment', 'service', 'product');
}

// ---------------------------------------------------------------------------
// Happy path — view-model wiring (viewName + viewData) and rendered HTML
// ---------------------------------------------------------------------------

it('renders the treatment report for an authorized owner, wired from the treatment/billing/clinical data', function (): void {
    /** @var FakePdfBuilder $fake */
    $fake = Pdf::fake();
    $setup = trSetup();

    $this->actingAs($setup['owner'])
        ->get(route('treatments.report', $setup['treatment']))
        ->assertOk();

    $pdf = trCapturedPdf($fake);
    $data = $pdf->viewData;

    expect($pdf->viewName)->toBe('pdf.treatment-report')
        ->and($data['patient']['fullName'])->toBe('Ayşe Yılmaz')
        ->and($data['doctor']['displayName'])->toBe($setup['doctor']->display_name)
        ->and($data['serviceLines'][0]['name'])->toBe('Tırnak Bakımı')
        ->and($data['productLines'][0]['name'])->toBe('Nasır Kremi')
        ->and($data['totalAmount'])->toContain('300')
        ->and($data['paidTotal'])->toContain('200')
        ->and($data['remainingBalance'])->toContain('100')
        ->and($data['details']['complaint'])->toBe('Sol ayak başparmağında ağrı')
        ->and($data['details']['diagnosis'])->toBe('Batık tırnak')
        ->and($data['details']['treatmentProcess'])->toBe('Kısmi tırnak eksizyonu uygulandı')
        ->and($data['notes'])->toBe('Kontrol 2 hafta sonra.')
        ->and($data['documentNumber'])->toStartWith((string) now()->year);

    // The Blade template itself renders correctly end to end (not just the view-model array).
    $html = $pdf->getHtml();
    expect($html)->toContain('Ayşe Yılmaz')
        ->toContain('Tırnak Bakımı')
        ->toContain('Nasır Kremi')
        ->toContain('Sol ayak başparmağında ağrı');
});

// ---------------------------------------------------------------------------
// showPayments — itemized payments list gated on transactions.viewAny;
// paid total / remaining balance always shown regardless
// ---------------------------------------------------------------------------

it('includes the itemized payments list for a role with transactions.viewAny (owner)', function (): void {
    $fake = Pdf::fake();
    $setup = trSetup();

    $this->actingAs($setup['owner'])
        ->get(route('treatments.report', $setup['treatment']))
        ->assertOk();

    $pdf = trCapturedPdf($fake);
    $data = $pdf->viewData;

    expect($data['showPayments'])->toBeTrue()
        ->and($data['payments'])->toHaveCount(2);

    $html = $pdf->getHtml();
    expect($html)->toContain('Nakit')->toContain('Kart');
});

it('omits the itemized payments list for assistant (lacks transactions.viewAny) while keeping paid total and balance', function (): void {
    $fake = Pdf::fake();
    $setup = trSetup();

    $this->actingAs($setup['assistant'])
        ->get(route('treatments.report', $setup['treatment']))
        ->assertOk();

    $pdf = trCapturedPdf($fake);
    $data = $pdf->viewData;

    expect($data['showPayments'])->toBeFalse()
        ->and($data['payments'])->toBe([])
        ->and($data['paidTotal'])->toContain('200')
        ->and($data['remainingBalance'])->toContain('100');

    $html = $pdf->getHtml();
    expect($html)->not->toContain('Nakit')->not->toContain('Kart');
});

// ---------------------------------------------------------------------------
// Authorization — reuses treatments.view: ownership branch + viewAll
// ---------------------------------------------------------------------------

it('doctor can produce the report for their own treatment (ownership branch, no treatments.viewAll needed)', function (): void {
    Pdf::fake();
    $setup = trSetup();

    $this->actingAs($setup['doctorUser'])
        ->get(route('treatments.report', $setup['treatment']))
        ->assertOk();
});

it("doctor gets 403 on a colleague's treatment report (lacks treatments.viewAll)", function (): void {
    Pdf::fake();
    $setup = trSetup();

    $otherDoctorUser = User::factory()->create();
    trRole($otherDoctorUser, 'doctor', $setup['clinic']->id);
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $setup['clinic']->id, 'user_id' => $otherDoctorUser->id]);
    $otherPatient = Patient::factory()->create(['clinic_id' => $setup['clinic']->id]);
    $otherTreatment = Treatment::factory()->completed()->create([
        'clinic_id' => $setup['clinic']->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $otherPatient->id,
    ]);

    $this->actingAs($setup['doctorUser'])
        ->get(route('treatments.report', $otherTreatment))
        ->assertForbidden();
});

it('receptionist (treatments.viewAll, no own doctor profile) can produce the report', function (): void {
    Pdf::fake();
    $setup = trSetup();

    $this->actingAs($setup['receptionist'])
        ->get(route('treatments.report', $setup['treatment']))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('clinic A cannot produce clinic B treatment report (404 via ClinicScope route binding)', function (): void {
    Pdf::fake();
    $setupA = trSetup();
    $setupB = trSetup();

    $this->actingAs($setupA['owner'])
        ->get(route('treatments.report', $setupB['treatment']))
        ->assertNotFound();
});
