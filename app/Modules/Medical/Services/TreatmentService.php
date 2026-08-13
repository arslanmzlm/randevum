<?php

namespace App\Modules\Medical\Services;

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\StockMovementReason;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentProductLine;
use App\Models\User;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Billing\Contracts\PaymentPlanCreatorContract;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Exceptions\TreatmentVoidBlockedException;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Modules\Medical\Repositories\TreatmentRepository;
use App\Modules\Scheduling\Contracts\AppointmentLifecycleContract;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

class TreatmentService
{
    /** Vertical slug the treatment flow is built for. */
    private const PODIATRY_VERTICAL_SLUG = 'podiatry';

    public function __construct(
        private TreatmentRepository $treatmentRepository,
        private CaseRepository $caseRepository,
        private StatusLogService $statusLogService,
        private AppointmentLifecycleContract $appointmentLifecycle,
        private PaymentRecorderContract $paymentRecorder,
        private PaymentPlanCreatorContract $paymentPlanCreator,
        private StockAdjusterContract $stockAdjuster,
        private BalanceReaderContract $balanceReader,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Idempotent: start a treatment for an appointment.
     *
     * - Returns the existing treatment if already a Draft.
     * - Throws 422 if the treatment is already Completed (caller redirects to show).
     * - Creates the Treatment (Draft) and marks the appointment Arrived.
     *
     * @throws ValidationException
     */
    public function start(Appointment $appointment, User $actor): Treatment
    {
        $this->assertStartable($appointment);
        $this->assertVerticalMatch();

        // Load existing treatment if any (ignores ClinicScope since we already hold the appointment)
        $existing = Treatment::withoutGlobalScopes()
            ->where('appointment_id', $appointment->id)
            ->first();

        if ($existing !== null) {
            // Completed → controller redirects to show; Draft → controller redirects to process.
            return $existing;
        }

        return DB::transaction(function () use ($appointment, $actor): Treatment {
            $treatment = $this->treatmentRepository->create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'status' => TreatmentStatus::Draft->value,
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $treatment,
                null,
                TreatmentStatus::Draft->value,
                $actor,
            );

            $this->appointmentLifecycle->markArrived($appointment, $actor);

            return $treatment;
        });
    }

    /**
     * Complete a Draft treatment in one transaction.
     *
     * Order of operations (spec §complete):
     *   1. Resolve / create case
     *   2. Insert line items + compute totals
     *   3. Clinical fields + notes + totals, Draft → Completed + completed_at + status_log
     *   4. Deduct stock per product line
     *   5. Record optional payment
     *   6. Book follow-up appointment(s)
     *   7. Mark appointment Arrived → Completed
     *
     * @param  array<string, mixed>  $data  Validated by CompleteTreatmentRequest
     * @return array{created: list<Appointment>, skipped: list<string>}
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function complete(Treatment $treatment, array $data, User $actor): array
    {
        if ($treatment->status !== TreatmentStatus::Draft) {
            throw ValidationException::withMessages([
                'treatment' => [__('treatment.errors.already_completed')],
            ]);
        }

        $treatment->loadMissing('appointment');
        $appointment = $treatment->appointment;

        return DB::transaction(function () use ($treatment, $appointment, $data, $actor): array {
            // 1. Case resolution
            $caseId = $this->resolveCase($treatment, $data, $actor);

            // 2. Insert line items + compute totals
            $this->writeServiceLines($treatment, $data['services'] ?? []);
            $this->writeProductLines($treatment, $data['products'] ?? []);

            $discountAmount = (string) ($data['discount_amount'] ?? 0);
            [$subtotal, $total] = $this->computeTotals($treatment, $discountAmount);

            // 3. Clinical fields + Draft → Completed
            $this->treatmentRepository->update($treatment, [
                'complaint' => $data['details']['complaint'] ?? null,
                'diagnosis' => $data['details']['diagnosis'] ?? null,
                'treatment_process' => $data['details']['treatment_process'] ?? null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discountAmount,
                'total_amount' => $total,
                'notes' => $data['notes'] ?? null,
                'case_id' => $caseId,
                'status' => TreatmentStatus::Completed->value,
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $treatment,
                TreatmentStatus::Draft->value,
                TreatmentStatus::Completed->value,
                $actor,
            );

            // 4. Stock deduction (negative allowed per domain rules). Locks are taken in
            // product_id order so two completions sharing products can't deadlock by
            // acquiring the same rows in opposite submission order.
            foreach ($treatment->productLines->sortBy('product_id') as $line) {
                $this->stockAdjuster->adjust(
                    $line->product_id,
                    -$line->quantity,
                    StockMovementReason::TreatmentUsage,
                    $treatment->id,
                    null,
                    $actor,
                );
            }

            // 5. Optional payment — either a split payment set or a taksit (installment) plan.
            $installmentPlan = $data['installment_plan'] ?? null;

            if (! empty($installmentPlan)) {
                $this->paymentPlanCreator->create([
                    'patient_id' => $treatment->patient_id,
                    'treatment_id' => $treatment->id,
                    'total_amount' => $total,
                    'down_payment' => $installmentPlan['down_payment'] ?? null,
                    'down_payment_method' => $installmentPlan['down_payment_method'] ?? null,
                    'installment_count' => $installmentPlan['installment_count'],
                    'installments' => $installmentPlan['installments'],
                ], $actor);
            } else {
                $payments = $data['payments'] ?? [];

                // bcmath — matches Billing's overshoot checks, avoids float drift on a hard cap.
                $paidTotal = array_reduce(
                    $payments,
                    fn (string $carry, array $payment): string => bcadd($carry, (string) $payment['amount'], 2),
                    '0.00',
                );

                if (bccomp($paidTotal, $total, 2) > 0) {
                    throw ValidationException::withMessages([
                        'payments' => [__('treatment.errors.payments_exceed_total')],
                    ]);
                }

                foreach ($payments as $payment) {
                    $this->paymentRecorder->record([
                        'amount' => $payment['amount'],
                        'payment_method' => $payment['method'],
                        'patient_id' => $treatment->patient_id,
                        'treatment_id' => $treatment->id,
                    ], $actor);
                }
            }

            // 6. Follow-up appointment(s)
            $followUpResult = ['created' => [], 'skipped' => []];
            $followUp = $data['follow_up'] ?? null;

            if (! empty($followUp) && ($followUp['mode'] ?? 'none') !== 'none') {
                $followUpResult = $this->appointmentLifecycle->scheduleFollowUps([
                    'doctor_id' => $treatment->doctor_id,
                    'patient_id' => $treatment->patient_id,
                    'case_id' => $caseId,
                    'service_id' => $followUp['service_id'] ?? null,
                    'occurrences' => $followUp['occurrences'] ?? [],
                ], $actor);
            }

            // 7. Appointment Arrived → Completed
            $this->appointmentLifecycle->markCompleted($appointment, $actor, $caseId);

            return $followUpResult;
        });
    }

    /**
     * Void a completed treatment: the record was entered in error and must count as if it
     * never happened. Not a refund — money already collected is the caller's problem to
     * refund FIRST, which is why any non-zero net collection blocks the transition.
     *
     * No time limit by owner decision: a mis-entered treatment stays voidable forever, so
     * this deliberately does not consult the edit/delete windows in config/platform.php.
     *
     * Stock return is per product line, not all-or-nothing: material actually consumed
     * before the mis-entry was noticed never goes back. $restockLineIds is REQUIRED and
     * carries the caller's decision verbatim — no implicit "return everything" default, so
     * a caller that forgets the choice cannot silently inflate stock. Pass [] to void
     * without returning anything.
     *
     * @param  list<int>  $restockLineIds  `treatment_products.id` values to return to stock
     *
     * @throws TreatmentVoidBlockedException
     * @throws ValidationException
     * @throws \Throwable
     */
    public function void(Treatment $treatment, User $actor, array $restockLineIds): Treatment
    {
        return DB::transaction(function () use ($treatment, $actor, $restockLineIds): Treatment {
            // Lock first, then read the paid total: a payment being recorded concurrently
            // takes the same row lock (TreatmentReader::completedTotalCap), so the two
            // serialize instead of both reading a stale "nothing collected".
            $locked = $this->treatmentRepository->lockForUpdate($treatment->id) ?? $treatment;

            if ($locked->status !== TreatmentStatus::Completed) {
                throw new TreatmentVoidBlockedException(__('treatment.errors.void_not_completed'));
            }

            $paid = $this->balanceReader->paidTotalForTreatment($locked->id);

            // Non-zero either way blocks: positive = money still held, negative = an
            // over-refund the clinic owes back. Zero means every payment was refunded.
            if (bccomp($paid, '0', 2) !== 0) {
                throw new TreatmentVoidBlockedException(__('treatment.errors.void_has_payments', [
                    'amount' => Number::currency(
                        (float) $paid,
                        $this->clinicContext->currency(),
                        $this->clinicContext->locale(),
                    ),
                ]));
            }

            // A plan opened but not yet collected sums to zero, so the paid-total guard above
            // cannot see it. Voiding then leaves the schedule running against a treatment that
            // no longer exists. The void never cancels the plan itself — money decisions stay
            // deliberate, so the caller closes or cancels the plan first.
            $openPlan = $this->balanceReader->openPaymentPlanSummaryForTreatment($locked->id);

            if ($openPlan !== null) {
                throw new TreatmentVoidBlockedException(__('treatment.errors.void_has_open_payment_plan', [
                    'count' => $openPlan['installment_count'],
                    'amount' => Number::currency(
                        (float) $openPlan['remaining_amount'],
                        $this->clinicContext->currency(),
                        $this->clinicContext->locale(),
                    ),
                ]));
            }

            $restockLines = $this->resolveRestockLines($locked, $restockLineIds);

            // Money is zeroed, quantities and unit_price snapshots are not: the clinical
            // record of what was used stays readable, only its monetary weight disappears.
            $locked->serviceLines()->update(['discount_amount' => 0, 'subtotal' => 0]);
            $locked->productLines()->update(['discount_amount' => 0, 'subtotal' => 0]);

            $this->treatmentRepository->update($locked, [
                'subtotal_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
                'status' => TreatmentStatus::Voided->value,
                'updated_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $locked,
                TreatmentStatus::Completed->value,
                TreatmentStatus::Voided->value,
                $actor,
            );

            // Only product lines move stock; service lines have nothing to return. Locks are
            // taken in product_id order for the same deadlock reason as complete(). Stock may
            // go negative in the other direction, so no ceiling guard here either.
            foreach ($restockLines->sortBy('product_id') as $line) {
                $this->stockAdjuster->adjust(
                    $line->product_id,
                    $line->quantity,
                    StockMovementReason::TreatmentVoid,
                    $locked->id,
                    null,
                    $actor,
                );
            }

            return $locked;
        });
    }

    /**
     * Map the caller's chosen line ids onto this treatment's own product lines.
     *
     * An id that is not one of this treatment's product lines is rejected rather than
     * skipped: silently dropping it would let a tampered or stale payload restock another
     * treatment's (or another clinic's) products under this void.
     *
     * @param  list<int>  $restockLineIds
     * @return Collection<int, TreatmentProductLine>
     *
     * @throws ValidationException
     */
    private function resolveRestockLines(Treatment $treatment, array $restockLineIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $restockLineIds)));

        if ($ids === []) {
            return new Collection;
        }

        $lines = $treatment->productLines()->whereIn('id', $ids)->get();

        if ($lines->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'restock_line_ids' => [__('treatment.errors.void_restock_line_mismatch')],
            ]);
        }

        return $lines;
    }

    /**
     * Treatments list for a patient's history panel, own/all scoped.
     *
     * Users without `treatments.viewAll` see only their own doctor's treatments.
     *
     * @return Collection<int, Treatment>
     */
    public function listForPatient(Patient $patient, User $user): Collection
    {
        $doctorId = $user->can('treatments.viewAll') ? null : $user->doctor?->id;

        return $this->treatmentRepository->forPatient($patient, $doctorId);
    }

    /**
     * Paginated treatment list for the active clinic, scoped to the user's visibility.
     *
     * Users with `treatments.viewAll` see every doctor's treatments (further narrowable
     * by the doctor filter in the request). Users without it are hard-scoped to their own
     * doctor profile; a user with no profile receives an empty paginator.
     *
     * @return LengthAwarePaginator<Treatment>
     */
    public function listForActiveClinic(User $user): LengthAwarePaginator
    {
        $clinic = $this->clinicContext->clinicOrFail();

        if ($user->can('treatments.viewAll')) {
            $doctorIds = null;
        } else {
            $ownId = $user->doctor?->id;

            if ($ownId === null) {
                return new LengthAwarePaginator([], 0, 20);
            }

            $doctorIds = [$ownId];
        }

        return $this->treatmentRepository->paginateForActiveClinic($doctorIds, $clinic->timezone);
    }

    /**
     * Resolve case mode for the complete flow. Returns the resolved case_id or null.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function resolveCase(Treatment $treatment, array $data, User $actor): ?int
    {
        $mode = $data['case_mode'] ?? 'none';

        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'existing') {
            $case = $this->caseRepository->findOpen((int) $data['case_id']);

            if ($case === null) {
                throw ValidationException::withMessages([
                    'case_id' => [__('treatment.errors.case_not_found')],
                ]);
            }

            if ($case->patient_id !== $treatment->patient_id) {
                throw ValidationException::withMessages([
                    'case_id' => [__('treatment.errors.case_patient_mismatch')],
                ]);
            }

            if ($case->doctor_id !== $treatment->doctor_id) {
                throw ValidationException::withMessages([
                    'case_id' => [__('treatment.errors.case_doctor_mismatch')],
                ]);
            }

            return $case->id;
        }

        // mode === 'new'
        $clinic = $this->clinicContext->clinicOrFail();

        $case = $this->caseRepository->create([
            'patient_id' => $treatment->patient_id,
            'doctor_id' => $treatment->doctor_id,
            'vertical_id' => $clinic->vertical_id,
            'title' => $data['new_case_title'],
            'status' => CaseStatus::Open->value,
            'opened_at' => now(),
        ]);

        $this->statusLogService->record(
            $case,
            null,
            CaseStatus::Open->value,
            $actor,
        );

        return $case->id;
    }

    /**
     * Write service line rows for the treatment (replaces any existing ones).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeServiceLines(Treatment $treatment, array $lines): void
    {
        $this->writeLines($treatment->serviceLines(), 'service_id', $lines, $treatment->clinic_id);
    }

    /**
     * Write product line rows for the treatment (replaces any existing ones).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeProductLines(Treatment $treatment, array $lines): void
    {
        $this->writeLines($treatment->productLines(), 'product_id', $lines, $treatment->clinic_id);
    }

    /**
     * Replace a treatment's line rows on the given relation, snapshotting unit_price and
     * computing subtotal = max(0, qty*unit_price − discount) per line. clinic_id is taken
     * from the parent treatment rather than left to the active-clinic default, so a line
     * can never land in a different clinic than the record it belongs to.
     *
     * @param  HasMany<Model, Treatment>  $relation
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines($relation, string $foreignKey, array $lines, int $clinicId): void
    {
        $relation->delete();

        foreach ($lines as $index => $line) {
            $qty = (int) $line['quantity'];
            $unitPrice = (string) $line['unit_price'];
            $discount = (string) ($line['discount_amount'] ?? 0);

            // bcmath end-to-end (decimal(12,2) columns) — avoids float rounding drift, matching Billing.
            $lineTotal = bcmul((string) $qty, $unitPrice, 2);
            $subtotal = bcsub($lineTotal, $discount, 2);

            if (bccomp($subtotal, '0', 2) < 0) {
                $subtotal = '0.00';
            }

            $relation->create([
                'clinic_id' => $clinicId,
                $foreignKey => (int) $line[$foreignKey],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'subtotal' => $subtotal,
                'note' => $line['note'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Compute treatment-level subtotal and total from freshly-written line rows.
     * Re-loads lines to avoid stale in-memory state after the delete+insert.
     *
     * subtotal = sum of all line subtotals
     * total    = max(0, subtotal − treatment-level discount)
     *
     * bcmath end-to-end (decimal(12,2) columns) — avoids float rounding drift, matching Billing.
     *
     * @return array{string, string} [subtotal, total]
     */
    private function computeTotals(Treatment $treatment, string $treatmentDiscount): array
    {
        $treatment->load('serviceLines', 'productLines');

        $subtotal = $treatment->serviceLines
            ->concat($treatment->productLines)
            ->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->subtotal, 2), '0.00');

        $total = bcsub($subtotal, $treatmentDiscount, 2);

        if (bccomp($total, '0', 2) < 0) {
            $total = '0.00';
        }

        return [$subtotal, $total];
    }

    /**
     * Guard that the appointment is in a startable status.
     *
     * @throws ValidationException
     */
    private function assertStartable(Appointment $appointment): void
    {
        $allowed = [
            AppointmentStatus::Confirmed,
            AppointmentStatus::Rescheduled,
            AppointmentStatus::Arrived,
        ];

        if (! in_array($appointment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'appointment' => [__('treatment.errors.appointment_not_startable')],
            ]);
        }
    }

    /**
     * Assert the active clinic runs the only vertical the treatment flow ships for.
     * Clinic-vertical consistency is enforced here per the cases-treatments domain rule.
     *
     * @throws ValidationException
     */
    private function assertVerticalMatch(): void
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        if ($clinic->vertical?->slug !== self::PODIATRY_VERTICAL_SLUG) {
            throw ValidationException::withMessages([
                'treatment' => [__('treatment.errors.vertical_mismatch')],
            ]);
        }
    }
}
