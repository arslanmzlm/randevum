<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPlanStatus;
use App\Enums\StockMovementReason;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Product;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\TreatmentProductLine;
use App\Models\TreatmentServiceLine;
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

/**
 * Assign a clinic-scoped role (void tests).
 */
function vdRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic with a completed treatment (one service line worth 200.00) plus its doctor's user.
 *
 * @return array{clinic: Clinic, patient: Patient, doctor: Doctor, doctorUser: User, treatment: Treatment, line: TreatmentServiceLine}
 */
function vdSetup(TreatmentStatus $status = TreatmentStatus::Completed): array
{
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => '250.00',
        'discount_amount' => '50.00',
        'total_amount' => '200.00',
        'status' => $status,
        'completed_at' => $status === TreatmentStatus::Completed ? now() : null,
        'created_by' => $doctorUser->id,
    ]);

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);
    $line = TreatmentServiceLine::create([
        'clinic_id' => $clinic->id,
        'treatment_id' => $treatment->id,
        'service_id' => $service->id,
        'quantity' => 1,
        'unit_price' => '250.00',
        'discount_amount' => '50.00',
        'subtotal' => '200.00',
        'sort_order' => 0,
    ]);

    return compact('clinic', 'patient', 'doctor', 'doctorUser', 'treatment', 'line');
}

/**
 * A product line on the treatment, plus the product it consumed.
 *
 * @return array{product: Product, line: TreatmentProductLine}
 */
function vdProductLine(Treatment $treatment, int $quantity = 3, int $stock = 10): array
{
    $product = Product::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'current_stock' => $stock,
    ]);

    $line = TreatmentProductLine::create([
        'clinic_id' => $treatment->clinic_id,
        'treatment_id' => $treatment->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'unit_price' => '25.00',
        'discount_amount' => '0.00',
        'subtotal' => (string) ($quantity * 25).'.00',
        'sort_order' => 0,
    ]);

    return compact('product', 'line');
}

/**
 * A settled cash payment booked against the treatment.
 */
function vdPayment(Treatment $treatment, string $amount = '200.00'): Transaction
{
    return Transaction::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'patient_id' => $treatment->patient_id,
        'treatment_id' => $treatment->id,
        'amount' => $amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subHour(),
    ]);
}

/**
 * An Active three-installment plan (3 × 150.00) booked against the treatment, with nothing
 * collected — so only the plan guard, never the paid-total guard, can block the void.
 */
function vdPaymentPlan(Treatment $treatment, InstallmentStatus $status = InstallmentStatus::Pending): PaymentPlan
{
    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'patient_id' => $treatment->patient_id,
        'treatment_id' => $treatment->id,
        'total_amount' => '450.00',
        'installment_count' => 3,
    ]);

    foreach (range(1, 3) as $sequence) {
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $treatment->clinic_id,
            'payment_plan_id' => $plan->id,
            'sequence' => $sequence,
            'due_date' => now()->addMonths($sequence)->toDateString(),
            'amount' => '150.00',
            'status' => $status,
        ]);
    }

    return $plan;
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('lets an owner void any completed treatment in the clinic', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('lets a manager void any completed treatment in the clinic', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $manager = User::factory()->create();
    vdRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('lets a doctor void their own treatment', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'treatment' => $treatment] = vdSetup();
    vdRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('forbids a doctor voiding another doctor treatment in the same clinic', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $otherUser = User::factory()->create();
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherUser->id]);
    vdRole($otherUser, 'doctor', $clinic->id);

    $this->actingAs($otherUser)
        ->post(route('treatments.void', $treatment))
        ->assertForbidden();

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Completed);
});

it('forbids roles without treatments.void', function (string $role): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $user = User::factory()->create();
    vdRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('treatments.void', $treatment))
        ->assertForbidden();

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Completed);
})->with(['receptionist', 'assistant']);

// ---------------------------------------------------------------------------
// Transition guards
// ---------------------------------------------------------------------------

it('refuses to void a draft treatment', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup(TreatmentStatus::Draft);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment))
        ->assertSessionHas('toasts.0.severity', 'warn')
        ->assertSessionHas('toasts.0.summary', __('treatment.errors.void_not_completed'));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Draft);
});

it('refuses to void an already voided treatment', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('treatments.void', $treatment));

    // The first void's success toast is still flashed; drop it so index 0 is this request's.
    $this->flushSession();

    $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment))
        ->assertSessionHas('toasts.0.summary', __('treatment.errors.void_not_completed'));

    expect(StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'treatment')
        ->where('to_status', TreatmentStatus::Voided->value)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Collected money blocks the void
// ---------------------------------------------------------------------------

it('refuses to void a treatment with a collected payment and names the amount', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    vdPayment($treatment, '200.00');

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $response = $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment))
        ->assertSessionHas('toasts.0.severity', 'warn');

    $message = $response->getSession()->get('toasts')[0]['summary'];

    expect($message)->toContain('200')
        ->and(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Completed);
});

it('allows the void once the payment has been refunded', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    $payment = vdPayment($treatment, '200.00');

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), ['amount' => '200.00', 'reason' => 'Mis-entry'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

// ---------------------------------------------------------------------------
// An open installment plan blocks the void
// ---------------------------------------------------------------------------

it('refuses to void a treatment with an open payment plan and names the schedule', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    vdPaymentPlan($treatment);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $response = $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment))
        ->assertSessionHas('toasts.0.severity', 'warn');

    $message = $response->getSession()->get('toasts')[0]['summary'];

    expect($message)->toContain('3')
        ->and($message)->toContain('450')
        ->and(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Completed);
});

it('allows the void once the plan has been cancelled', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    $plan = vdPaymentPlan($treatment);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan))
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('allows the void when the plan is fully collected and completed', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    $plan = vdPaymentPlan($treatment, InstallmentStatus::Paid);
    $plan->update(['status' => PaymentPlanStatus::Completed]);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('does not let another clinic open plan block a void', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    // Same treatment id, foreign clinic: only the clinic scope keeps this row out of the guard.
    $foreign = Clinic::factory()->create();
    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $foreign->id,
        'patient_id' => Patient::factory()->create(['clinic_id' => $foreign->id])->id,
        'treatment_id' => $treatment->id,
        'total_amount' => '450.00',
        'installment_count' => 3,
    ]);
    PaymentPlanInstallment::factory()->create([
        'clinic_id' => $foreign->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'amount' => '450.00',
    ]);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

// ---------------------------------------------------------------------------
// Effects
// ---------------------------------------------------------------------------

it('zeroes the treatment totals and its line amounts, keeping quantity and unit_price', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment, 'line' => $line] = vdSetup();

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('treatments.void', $treatment));

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshLine = TreatmentServiceLine::find($line->id);

    expect((float) $fresh->subtotal_amount)->toBe(0.0)
        ->and((float) $fresh->discount_amount)->toBe(0.0)
        ->and((float) $fresh->total_amount)->toBe(0.0)
        ->and((float) $freshLine->subtotal)->toBe(0.0)
        ->and((float) $freshLine->discount_amount)->toBe(0.0)
        ->and($freshLine->quantity)->toBe(1)
        ->and((float) $freshLine->unit_price)->toBe(250.0);
});

it('writes a completed → voided status log with the acting user', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('treatments.void', $treatment));

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'treatment')
        ->where('loggable_id', $treatment->id)
        ->where('to_status', TreatmentStatus::Voided->value)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe(TreatmentStatus::Completed->value)
        ->and($log->to_status)->toBe(TreatmentStatus::Voided->value)
        ->and($log->by_user_id)->toBe($owner->id)
        ->and($log->clinic_id)->toBe($clinic->id);
});

// ---------------------------------------------------------------------------
// Per-line stock return
// ---------------------------------------------------------------------------

it('returns stock for a marked product line and records the movement', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    ['product' => $product, 'line' => $line] = vdProductLine($treatment, quantity: 3, stock: 7);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => [$line->id]])
        ->assertRedirect(route('treatments.show', $treatment));

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->sole();

    expect(Product::withoutGlobalScopes()->find($product->id)->current_stock)->toBe(10)
        ->and($movement->quantity)->toBe(3)
        ->and($movement->balance_after)->toBe(10)
        ->and($movement->reason)->toBe(StockMovementReason::TreatmentVoid)
        ->and($movement->treatment_id)->toBe($treatment->id)
        ->and($movement->clinic_id)->toBe($clinic->id)
        ->and($movement->created_by)->toBe($owner->id);
});

it('leaves stock untouched for an unmarked product line', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    ['product' => $product] = vdProductLine($treatment, quantity: 3, stock: 7);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => []])
        ->assertRedirect(route('treatments.show', $treatment));

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->count())->toBe(0)
        ->and(Product::withoutGlobalScopes()->find($product->id)->current_stock)->toBe(7)
        ->and(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);
});

it('returns only the marked lines when a treatment has several products', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    ['product' => $kept, 'line' => $keptLine] = vdProductLine($treatment, quantity: 2, stock: 5);
    ['product' => $consumed] = vdProductLine($treatment, quantity: 4, stock: 5);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => [$keptLine->id]]);

    expect(Product::withoutGlobalScopes()->find($kept->id)->current_stock)->toBe(7)
        ->and(Product::withoutGlobalScopes()->find($consumed->id)->current_stock)->toBe(5)
        ->and(StockMovement::withoutGlobalScopes()->where('treatment_id', $treatment->id)->count())->toBe(1);
});

it('creates no stock movement for a treatment made of service lines only', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('treatments.void', $treatment));

    expect(StockMovement::withoutGlobalScopes()->count())->toBe(0);
});

it('does not return stock twice when the same treatment is voided again', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    ['product' => $product, 'line' => $line] = vdProductLine($treatment, quantity: 3, stock: 7);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => [$line->id]]);

    $this->flushSession();

    $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => [$line->id]])
        ->assertSessionHas('toasts.0.summary', __('treatment.errors.void_not_completed'));

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->count())->toBe(1)
        ->and(Product::withoutGlobalScopes()->find($product->id)->current_stock)->toBe(10);
});

it('rejects a restock line id belonging to another treatment', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = vdSetup();
    ['product' => $product] = vdProductLine($treatment, quantity: 3, stock: 7);

    ['treatment' => $other] = vdSetup();
    ['line' => $otherLine] = vdProductLine($other, quantity: 3, stock: 7);

    $owner = User::factory()->create();
    vdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->from(route('treatments.show', $treatment))
        ->post(route('treatments.void', $treatment), ['restock_line_ids' => [$otherLine->id]])
        ->assertSessionHasErrors('restock_line_ids.0');

    expect(StockMovement::withoutGlobalScopes()->count())->toBe(0)
        ->and(Product::withoutGlobalScopes()->find($product->id)->current_stock)->toBe(7)
        ->and(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Completed);
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('does not let clinic A owner void a treatment belonging to clinic B', function (): void {
    ['treatment' => $treatmentB] = vdSetup();
    ['product' => $productB, 'line' => $lineB] = vdProductLine($treatmentB, quantity: 3, stock: 7);

    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    vdRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('treatments.void', $treatmentB), ['restock_line_ids' => [$lineB->id]])
        ->assertNotFound();

    expect(Treatment::withoutGlobalScopes()->find($treatmentB->id)->status)
        ->toBe(TreatmentStatus::Completed)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe(0)
        ->and(Product::withoutGlobalScopes()->find($productB->id)->current_stock)->toBe(7);
});
