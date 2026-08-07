<?php

use App\Models\Anamnesis;
use App\Models\AnamnesisField;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryAnamnesisFieldsSeeder;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class, VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
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
 * A podiatry clinic + patient with a filled anamnesis (select, boolean, unit, core-text
 * and one dynamic `extra` field).
 *
 * @return array{clinic: Clinic, patient: Patient, anamnesis: Anamnesis}
 */
function arSetup(): array
{
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
    ]);

    $anamnesis = Anamnesis::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'blood_type' => 'A+',
        'height_cm' => 170,
        'weight_kg' => 65.50,
        'diabetes' => 'type2',
        'hypertension' => true,
        'cardiovascular' => false,
        'extra' => ['current_foot_complaint' => 'Sol ayak başparmağında ağrı'],
    ]);

    return compact('clinic', 'patient', 'anamnesis');
}

// ---------------------------------------------------------------------------
// Happy path — viewName + rendered HTML, core section + derived BMI + dynamic extra
// ---------------------------------------------------------------------------

it('renders the anamnesis PDF wired from the filled fields, including the derived BMI and the dynamic extra section', function (): void {
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
        // A true core boolean flag renders as Evet.
        ->toContain('Evet')
        // Unit-suffixed height.
        ->toContain('170 cm')
        // Derived BMI: 65.50 / 1.70^2 ≈ 22.7 — never a stored column.
        ->toContain('22.7')
        // Dynamic `extra` field renders under its podiatry group heading.
        ->toContain(__('health.groups.podiatry'))
        ->toContain('Sol ayak başparmağında ağrı');
});

it('a false core boolean flag is omitted (same omit-unfilled behaviour as null)', function (): void {
    $fake = Pdf::fake();
    $setup = arSetup();

    $owner = User::factory()->create();
    arRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertOk();

    $html = arCapturedPdf($fake)->getHtml();

    expect($html)->toContain(__('health.fields.hypertension'))
        ->not->toContain(__('health.fields.cardiovascular'));
});

it("an inactive definition's stored value still prints in the PDF; an unfilled definition is omitted", function (): void {
    $fake = Pdf::fake();
    $setup = arSetup();

    AnamnesisField::where('key', 'diabetic_foot_history')->update(['is_active' => false]);
    $setup['anamnesis']->update([
        'extra' => array_merge($setup['anamnesis']->extra, ['diabetic_foot_history' => true]),
    ]);

    $owner = User::factory()->create();
    arRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.anamnesis.pdf', $setup['patient']))
        ->assertOk();

    $html = arCapturedPdf($fake)->getHtml();

    expect($html)->toContain(__('health.fields.diabetic_foot_history'))
        // foot_surgery_history has no stored value → never printed.
        ->not->toContain(__('health.fields.foot_surgery_history'));
});

it('the PDF empty-state fallback renders when the patient has no anamnesis yet', function (): void {
    $fake = Pdf::fake();
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
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
