<?php

namespace App\Modules\Medical\Services;

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentPlanCreatorContract;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Core\Contracts\AppointmentLifecycleContract;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Modules\Medical\Repositories\TreatmentRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreatmentService
{
    /** Morph slug used for the podiatry vertical detail table. */
    private const PODIATRY_DETAIL_SLUG = 'podiatry';

    public function __construct(
        private TreatmentRepository $treatmentRepository,
        private CaseRepository $caseRepository,
        private StatusLogService $statusLogService,
        private AppointmentLifecycleContract $appointmentLifecycle,
        private PaymentRecorderContract $paymentRecorder,
        private PaymentPlanCreatorContract $paymentPlanCreator,
        private StockAdjusterContract $stockAdjuster,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Idempotent: start a treatment for an appointment.
     *
     * - Returns the existing treatment if already a Draft.
     * - Throws 422 if the treatment is already Completed (caller redirects to show).
     * - Creates PodiatryTreatmentDetail + Treatment (Draft) and marks the appointment Arrived.
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
            $detail = PodiatryTreatmentDetail::create([]);

            $treatment = $this->treatmentRepository->create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'details_type' => self::PODIATRY_DETAIL_SLUG,
                'details_id' => $detail->id,
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
     *   2. Update detail fields + notes
     *   3. Insert line items + compute totals
     *   4. Draft → Completed + completed_at + status_log
     *   5. Deduct stock per product line
     *   6. Record optional payment
     *   7. Book follow-up appointment(s)
     *   8. Mark appointment Arrived → Completed
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

        $treatment->loadMissing('appointment', 'details');
        $appointment = $treatment->appointment;

        return DB::transaction(function () use ($treatment, $appointment, $data, $actor): array {
            // 1. Case resolution
            $caseId = $this->resolveCase($treatment, $data, $actor);

            // 2. Update detail fields + treatment notes
            $treatment->details->fill([
                'complaint' => $data['details']['complaint'] ?? null,
                'diagnosis' => $data['details']['diagnosis'] ?? null,
                'treatment_process' => $data['details']['treatment_process'] ?? null,
            ])->save();

            $treatment->notes = $data['notes'] ?? null;

            // 3. Insert line items + compute totals
            $this->writeServiceLines($treatment, $data['services'] ?? []);
            $this->writeProductLines($treatment, $data['products'] ?? []);

            [$subtotal, $total] = $this->computeTotals($treatment, (float) ($data['discount_amount'] ?? 0));

            // 4. Draft → Completed
            $this->treatmentRepository->update($treatment, [
                'subtotal_amount' => $subtotal,
                'discount_amount' => (float) ($data['discount_amount'] ?? 0),
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

            // 5. Stock deduction (negative allowed per domain rules)
            foreach ($treatment->productLines as $line) {
                $this->stockAdjuster->adjust($line->product_id, -$line->quantity);
            }

            // 6. Optional payment — either a split payment set or a taksit (installment) plan.
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

                $paidTotal = array_sum(array_map(
                    fn (array $payment): float => (float) $payment['amount'],
                    $payments,
                ));

                if (round($paidTotal, 2) > round($total, 2)) {
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

            // 7. Follow-up appointment(s)
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

            // 8. Appointment Arrived → Completed
            $this->appointmentLifecycle->markCompleted($appointment, $actor, $caseId);

            return $followUpResult;
        });
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
        $this->writeLines($treatment->serviceLines(), 'service_id', $lines);
    }

    /**
     * Write product line rows for the treatment (replaces any existing ones).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeProductLines(Treatment $treatment, array $lines): void
    {
        $this->writeLines($treatment->productLines(), 'product_id', $lines);
    }

    /**
     * Replace a treatment's line rows on the given relation, snapshotting unit_price and
     * computing subtotal = max(0, qty*unit_price − discount) per line.
     *
     * @param  HasMany<Model, Treatment>  $relation
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines($relation, string $foreignKey, array $lines): void
    {
        $relation->delete();

        foreach ($lines as $index => $line) {
            $qty = (int) $line['quantity'];
            $unitPrice = (float) $line['unit_price'];
            $discount = (float) ($line['discount_amount'] ?? 0);
            $subtotal = max(0, ($qty * $unitPrice) - $discount);

            $relation->create([
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
     * @return array{float, float} [subtotal, total]
     */
    private function computeTotals(Treatment $treatment, float $treatmentDiscount): array
    {
        $treatment->load('serviceLines', 'productLines');

        $subtotal = $treatment->serviceLines->sum('subtotal')
            + $treatment->productLines->sum('subtotal');

        $total = max(0, $subtotal - $treatmentDiscount);

        return [(float) $subtotal, (float) $total];
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
     * Assert the active clinic's vertical slug matches the podiatry detail morph slug.
     * Clinic-vertical / detail-type consistency is enforced here per the cases-treatments domain rule.
     *
     * @throws ValidationException
     */
    private function assertVerticalMatch(): void
    {
        $clinic = Clinic::with('vertical')->findOrFail($this->clinicContext->id());

        if ($clinic->vertical?->slug !== self::PODIATRY_DETAIL_SLUG) {
            throw ValidationException::withMessages([
                'treatment' => [__('treatment.errors.vertical_mismatch')],
            ]);
        }
    }
}
