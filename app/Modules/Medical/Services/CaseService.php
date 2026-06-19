<?php

namespace App\Modules\Medical\Services;

use App\Enums\CaseStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Modules\Medical\Repositories\TreatmentRepository;
use App\Support\ClinicContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseService
{
    /**
     * Allowed transitions: next statuses from each current status.
     *
     * @var array<string, list<CaseStatus>>
     */
    private const TRANSITIONS = [
        'open' => [CaseStatus::Suspended, CaseStatus::FollowUp, CaseStatus::Closed],
        'suspended' => [CaseStatus::Open, CaseStatus::Closed],
        'follow_up' => [CaseStatus::Open, CaseStatus::Closed],
        'closed' => [CaseStatus::Open],
    ];

    public function __construct(
        private CaseRepository $caseRepository,
        private TreatmentRepository $treatmentRepository,
        private StatusLogService $statusLogService,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Paginated case list for the active clinic, scoped to the user's visibility.
     *
     * Users with `cases.viewAll` see every doctor's cases. Users without it are
     * hard-scoped to their own doctor profile; a user with no profile gets an empty
     * paginator (they have no cases to see).
     *
     * @return LengthAwarePaginator<CaseRecord>
     */
    public function listForActiveClinic(User $user): LengthAwarePaginator
    {
        if ($user->can('cases.viewAll')) {
            $doctorIds = null;
        } else {
            $ownId = $user->doctor?->id;

            if ($ownId === null) {
                return new LengthAwarePaginator([], 0, 20);
            }

            $doctorIds = [$ownId];
        }

        return $this->caseRepository->paginateForActiveClinic($doctorIds);
    }

    /**
     * Next statuses reachable from the case's current status.
     *
     * @return list<string>
     */
    public function allowedTransitions(CaseRecord $case): array
    {
        $next = self::TRANSITIONS[$case->status->value] ?? [];

        return array_map(fn (CaseStatus $s) => $s->value, $next);
    }

    /**
     * Transition a case to a new status, set the relevant timestamps, and log to status_logs.
     *
     * @param  array<string, mixed>  $followUp  Optional; required when $to = FollowUp
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function changeStatus(CaseRecord $case, CaseStatus $to, User $actor, array $followUp = []): void
    {
        $allowed = self::TRANSITIONS[$case->status->value] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [__('case.errors.transition_not_allowed')],
            ]);
        }

        if ($to === CaseStatus::FollowUp && empty($followUp['follow_up_date'])) {
            throw ValidationException::withMessages([
                'follow_up_date' => [__('case.errors.follow_up_date_required')],
            ]);
        }

        DB::transaction(function () use ($case, $to, $actor, $followUp): void {
            $from = $case->status->value;

            $updates = ['status' => $to->value];

            match ($to) {
                CaseStatus::Closed => $updates['closed_at'] = now(),
                CaseStatus::Suspended => $updates['suspended_at'] = now(),
                CaseStatus::Open => array_merge($updates, ['closed_at' => null, 'suspended_at' => null]),
                CaseStatus::FollowUp => null,
            };

            // Clearing timestamps on → Open must be done explicitly
            if ($to === CaseStatus::Open) {
                $updates['closed_at'] = null;
                $updates['suspended_at'] = null;
            }

            if ($to === CaseStatus::FollowUp && ! empty($followUp['follow_up_date'])) {
                $updates['follow_up_date'] = $followUp['follow_up_date'];
                $updates['follow_up_note'] = $followUp['follow_up_note'] ?? null;
            }

            $case->fill($updates)->save();

            $this->statusLogService->record($case, $from, $to->value, $actor);
        });
    }

    /**
     * Update the case notes (always editable per deletion-retention rules).
     */
    public function updateNotes(CaseRecord $case, ?string $notes): void
    {
        $case->notes = $notes;
        $case->save();
    }

    /**
     * Update the follow-up date and note, independently of status.
     *
     * A Closed case may still carry a follow-up reminder.
     */
    public function updateFollowUp(CaseRecord $case, mixed $followUpDate, ?string $followUpNote): void
    {
        $case->follow_up_date = $followUpDate;
        $case->follow_up_note = $followUpNote;
        $case->save();
    }

    /**
     * Update the case title — only within the edit window of opened_at.
     *
     * After the window only status changes are allowed (deletion-retention rule).
     *
     * @throws ValidationException
     */
    public function updateTitle(CaseRecord $case, string $title): void
    {
        $window = (int) config('platform.edit_windows.case');

        if (now()->diffInSeconds($case->opened_at, absolute: true) > $window) {
            throw ValidationException::withMessages([
                'title' => [__('case.errors.edit_window_expired')],
            ]);
        }

        $case->title = $title;
        $case->save();
    }

    /**
     * Create a new Open case for a patient, optionally linking existing completed treatments.
     *
     * - When $data['treatment_ids'] is provided, the treatments' shared doctor becomes the case doctor.
     * - When no treatment_ids are supplied, $data['doctor_id'] is required.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function createForPatient(array $data, User $actor): CaseRecord
    {
        $treatmentIds = $data['treatment_ids'] ?? [];
        $clinic = Clinic::findOrFail($this->clinicContext->id());

        if (! empty($treatmentIds)) {
            $doctorId = $this->resolveDoctorFromTreatments($treatmentIds, (int) $data['patient_id'], $actor);
        } else {
            if (empty($data['doctor_id'])) {
                throw ValidationException::withMessages([
                    'doctor_id' => [__('case.errors.doctor_required')],
                ]);
            }

            $doctorId = (int) $data['doctor_id'];

            // Non-viewAll users can only create cases for their own doctor profile.
            if (! $actor->can('cases.viewAll') && $actor->doctor?->id !== $doctorId) {
                throw ValidationException::withMessages([
                    'doctor_id' => [__('case.errors.doctor_not_own')],
                ]);
            }
        }

        return DB::transaction(function () use ($data, $clinic, $doctorId, $treatmentIds, $actor): CaseRecord {
            $case = $this->caseRepository->create([
                'patient_id' => (int) $data['patient_id'],
                'doctor_id' => $doctorId,
                'vertical_id' => $clinic->vertical_id,
                'title' => $data['title'],
                'status' => CaseStatus::Open->value,
                'opened_at' => now(),
            ]);

            $this->statusLogService->record($case, null, CaseStatus::Open->value, $actor);

            if (! empty($treatmentIds)) {
                $this->attachTreatments($case, $treatmentIds);
            }

            return $case;
        });
    }

    /**
     * Link one or more ungrouped completed treatments to an existing open case.
     *
     * Each treatment must: have case_id === null, status === Completed,
     * patient_id === case.patient_id, doctor_id === case.doctor_id.
     * The case must be Open. All-or-nothing in a transaction.
     *
     * @param  list<int>  $treatmentIds
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function linkTreatments(CaseRecord $case, array $treatmentIds, User $actor): void
    {
        if ($case->status !== CaseStatus::Open) {
            throw ValidationException::withMessages([
                'case_id' => [__('case.errors.case_not_open')],
            ]);
        }

        DB::transaction(function () use ($case, $treatmentIds): void {
            $this->attachTreatments($case, $treatmentIds);
        });
    }

    /**
     * Cases for a patient's detail page, own/all scoped to the user, shaped for the panel.
     *
     * @return list<array{id: int, title: string, status: string, treatments_count: int, opened_at: string, follow_up_date: string|null}>
     */
    public function casesForPatient(int $patientId, User $user): array
    {
        $doctorId = $user->can('cases.viewAll') ? null : $user->doctor?->id;

        return $this->caseRepository->forPatient($patientId, $doctorId)
            ->map(fn (CaseRecord $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'status' => $c->status->value,
                'treatments_count' => (int) $c->treatments_count,
                'opened_at' => $c->opened_at->toIso8601String(),
                'follow_up_date' => $c->follow_up_date?->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }

    /**
     * Open cases for a patient + doctor, shaped for the treatment Process "link to case"
     * select. ClinicScope isolates the tenant.
     *
     * @return list<array{id: int, title: string, opened_at: string, treatments_count: int}>
     */
    public function openCasesForProcess(int $patientId, int $doctorId): array
    {
        return $this->caseRepository
            ->openForPatientAndDoctor($patientId, $doctorId)
            ->map(fn (CaseRecord $case) => [
                'id' => $case->id,
                'title' => $case->title,
                'opened_at' => $case->opened_at->toIso8601String(),
                'treatments_count' => (int) $case->treatments_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Ungrouped completed treatments for the case's patient + doctor, shaped for the
     * link-treatments dialog on the case Show page.
     *
     * @return list<array{id: int, title: string|null, completed_at: string|null, doctor_id: int, doctor_name: string, total_amount: string}>
     */
    public function ungroupedTreatmentsForCase(CaseRecord $case): array
    {
        return $this->treatmentRepository
            ->ungroupedCompletedFor($case->patient_id, $case->doctor_id)
            ->map(fn (Treatment $t) => [
                'id' => $t->id,
                'title' => $t->serviceLines->first()?->service?->name,
                'completed_at' => $t->completed_at?->toIso8601String(),
                'doctor_id' => (int) $t->doctor_id,
                'doctor_name' => $t->doctor->display_name,
                'total_amount' => (string) $t->total_amount,
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the case title may still be edited (within the edit window).
     */
    public function canEditTitle(CaseRecord $case): bool
    {
        $window = (int) config('platform.edit_windows.case');

        return now()->diffInSeconds($case->opened_at, absolute: true) <= $window;
    }

    /**
     * Resolve and validate the shared doctor from a set of treatment ids.
     *
     * All treatments must: be Completed, belong to the given patient, have no existing
     * case_id, and share the same doctor.
     *
     * @param  list<int>  $treatmentIds
     *
     * @throws ValidationException
     */
    private function resolveDoctorFromTreatments(array $treatmentIds, int $patientId, User $actor): int
    {
        $treatments = Treatment::whereIn('id', $treatmentIds)->get();

        if ($treatments->count() !== count($treatmentIds)) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_not_found')],
            ]);
        }

        foreach ($treatments as $treatment) {
            $this->assertTreatmentLinkable($treatment, $patientId, null, null);
        }

        $doctorIds = $treatments->pluck('doctor_id')->unique();

        if ($doctorIds->count() > 1) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatments_mixed_doctors')],
            ]);
        }

        $doctorId = (int) $doctorIds->first();

        // Non-viewAll users can only create cases for their own doctor profile.
        if (! $actor->can('cases.viewAll') && $actor->doctor?->id !== $doctorId) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.doctor_not_own')],
            ]);
        }

        return $doctorId;
    }

    /**
     * Attach treatments to a case and sync each treatment's appointment.case_id.
     * Caller is responsible for wrapping in a transaction.
     *
     * @param  list<int>  $treatmentIds
     *
     * @throws ValidationException
     */
    private function attachTreatments(CaseRecord $case, array $treatmentIds): void
    {
        $treatments = Treatment::whereIn('id', $treatmentIds)->with('appointment')->get();

        if ($treatments->count() !== count($treatmentIds)) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_not_found')],
            ]);
        }

        foreach ($treatments as $treatment) {
            $this->assertTreatmentLinkable($treatment, $case->patient_id, $case->doctor_id, $case);
        }

        foreach ($treatments as $treatment) {
            $treatment->case_id = $case->id;
            $treatment->save();

            if ($treatment->appointment !== null) {
                $treatment->appointment->case_id = $case->id;
                $treatment->appointment->save();
            }
        }
    }

    /**
     * Assert that a treatment can be linked to a case.
     *
     * Pass null for $expectedPatientId / $expectedDoctorId to skip those checks
     * (used during doctor resolution where case doesn't exist yet).
     *
     * @throws ValidationException
     */
    private function assertTreatmentLinkable(
        Treatment $treatment,
        ?int $expectedPatientId,
        ?int $expectedDoctorId,
        ?CaseRecord $case,
    ): void {
        if ($treatment->case_id !== null) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_already_linked')],
            ]);
        }

        if ($treatment->status !== TreatmentStatus::Completed) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_not_completed')],
            ]);
        }

        if ($expectedPatientId !== null && $treatment->patient_id !== $expectedPatientId) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_patient_mismatch')],
            ]);
        }

        if ($expectedDoctorId !== null && $treatment->doctor_id !== $expectedDoctorId) {
            throw ValidationException::withMessages([
                'treatment_ids' => [__('case.errors.treatment_doctor_mismatch')],
            ]);
        }
    }
}
