<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
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
 * Pulls the single PDF the fake recorded via toResponse() — mirrors
 * TreatmentReportTest::trCapturedPdf (the controller returns the PdfBuilder
 * directly as a Responsable, never calling ->save()).
 */
function arCapturedPdf(FakePdfBuilder $fake): PdfBuilder
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
 * Assign a clinic-scoped role (anamnesis-report tests).
 */
function arRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A podiatry clinic + patient with a filled anamnesis (select, boolean, unit, text fields).
 *
 * @return array{clinic: Clinic, patient: Patient, anamnesis: PodiatryAnamnesis}
 */
function arSetup(): array
{
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
    ]);

    $anamnesis = PodiatryAnamnesis::create([
        'blood_type' => 'A+',
        'height_cm' => 170,
        'weight_kg' => '65.50',
        'diabetes' => 'type2',
        'hypertension' => true,
        'current_foot_complaint' => 'Sol ayak başparmağında ağrı',
    ]);
    $patient->anamnesis()->associate($anamnesis);
    $patient->save();

    return compact('clinic', 'patient', 'anamnesis');
}

// ---------------------------------------------------------------------------
// Happy path — viewName + rendered HTML
// ---------------------------------------------------------------------------

it('renders the anamnesis PDF wired from the filled fields', function (): void {
    /** @var FakePdfBuilder $fake */
    $fake = Pdf::fake();
    $setup = arSetup();

    $owner = User::factory()->create();
    arRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertOk();

    $pdf = arCapturedPdf($fake);

    expect($pdf->viewName)->toBe('pdf.anamnesis')
        ->and($pdf->viewData['patient']['fullName'])->toBe('Ayşe Yılmaz');

    $html = $pdf->getHtml();
    expect($html)->toContain('Ayşe Yılmaz')
        // Select option localized label (diabetes: type2).
        ->toContain('Tip 2')
        // Boolean field rendered as Evet/Hayır.
        ->toContain('Evet')
        // Unit-suffixed height.
        ->toContain('170 cm')
        ->toContain('Sol ayak başparmağında ağrı');
});

it('the PDF empty-state fallback renders when the patient has no anamnesis yet', function (): void {
    $fake = Pdf::fake();
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $owner = User::factory()->create();
    arRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.anamnesis.pdf', $patient))
        ->assertOk();

    $pdf = arCapturedPdf($fake);
    expect($pdf->getHtml())->toContain('Doldurulmuş anamnez kaydı bulunmuyor.');
});

// ---------------------------------------------------------------------------
// Authorization — reuses patients.view (clinic-wide), assistant/receptionist included
// ---------------------------------------------------------------------------

it('assistant (no anamnesis.update needed, view is clinic-wide) can produce the PDF', function (): void {
    Pdf::fake();
    $setup = arSetup();
    $assistant = User::factory()->create();
    arRole($assistant, 'assistant', $setup['clinic']->id);

    $this->actingAs($assistant)
        ->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertOk();
});

it('receptionist can produce the PDF', function (): void {
    Pdf::fake();
    $setup = arSetup();
    $receptionist = User::factory()->create();
    arRole($receptionist, 'receptionist', $setup['clinic']->id);

    $this->actingAs($receptionist)
        ->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertOk();
});

it('guest is redirected to login on the PDF route', function (): void {
    $setup = arSetup();

    $this->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('clinic A cannot produce clinic B patient anamnesis PDF (404 via ClinicScope route binding)', function (): void {
    Pdf::fake();
    $setupA = arSetup();
    $setupB = arSetup();

    $ownerA = User::factory()->create();
    arRole($ownerA, 'owner', $setupA['clinic']->id);

    $this->actingAs($ownerA)
        ->get(route('patients.anamnesis.pdf', $setupB['patient']))
        ->assertNotFound();
});
