<?php

namespace App\Modules\Medical\Services;

use App\Enums\FollowUpStatus;
use App\Models\CaseRecord;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\User;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Repositories\FollowUpRepository;
use App\Support\ClinicContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowUpService
{
    public function __construct(
        private FollowUpRepository $repository,
        private StatusLogService $statusLogService,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Create a manual follow-up for a patient, optionally linked to one of their cases.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws \Throwable
     */
    public function create(array $data, User $actor): FollowUp
    {
        $patient = Patient::find($data['patient_id']);

        if ($patient === null) {
            throw ValidationException::withMessages([
                'patient_id' => [__('follow_up.errors.patient_not_found')],
            ]);
        }

        $caseId = $data['case_id'] ?? null;

        if ($caseId !== null) {
            $case = CaseRecord::find($caseId);

            if ($case === null) {
                throw ValidationException::withMessages([
                    'case_id' => [__('follow_up.errors.case_not_found')],
                ]);
            }

            if ($case->patient_id !== $patient->id) {
                throw ValidationException::withMessages([
                    'case_id' => [__('follow_up.errors.case_patient_mismatch')],
                ]);
            }
        }

        return DB::transaction(function () use ($data, $patient, $caseId, $actor): FollowUp {
            $followUp = $this->repository->create([
                'patient_id' => $patient->id,
                'case_id' => $caseId,
                'follow_up_type_id' => $data['follow_up_type_id'] ?? null,
                'due_date' => $data['due_date'],
                'note' => $data['note'] ?? null,
                'status' => FollowUpStatus::Open->value,
                'created_by_user_id' => $actor->id,
            ]);

            $this->statusLogService->record($followUp, null, FollowUpStatus::Open->value, $actor);

            return $followUp;
        });
    }

    /**
     * @throws ValidationException
     * @throws \Throwable
     */
    public function complete(FollowUp $followUp, ?string $resultNote, User $actor): void
    {
        $this->assertOpen($followUp);

        DB::transaction(function () use ($followUp, $resultNote, $actor): void {
            $from = $followUp->status->value;

            $followUp->fill([
                'status' => FollowUpStatus::Done->value,
                'completed_at' => now(),
                'completed_by_user_id' => $actor->id,
                'result_note' => $resultNote,
            ])->save();

            $this->statusLogService->record($followUp, $from, FollowUpStatus::Done->value, $actor);
        });
    }

    /**
     * @throws ValidationException
     * @throws \Throwable
     */
    public function cancel(FollowUp $followUp, User $actor): void
    {
        $this->assertOpen($followUp);

        DB::transaction(function () use ($followUp, $actor): void {
            $from = $followUp->status->value;

            $followUp->fill(['status' => FollowUpStatus::Cancelled->value])->save();

            $this->statusLogService->record($followUp, $from, FollowUpStatus::Cancelled->value, $actor);
        });
    }

    /**
     * The case-detail panel projection: open follow-ups first (due_date asc), then the
     * rest (done/cancelled) newest-completed-first.
     *
     * @return list<array{id: int, status: string, type: array{id: int, name: string}|null, due_date: string, note: string|null, completed_at: string|null, completed_by: string|null, result_note: string|null, is_overdue: bool}>
     */
    public function forCase(CaseRecord $case): array
    {
        $today = now($this->clinicContext->timezone())->toDateString();

        return $this->repository->forCase($case->id)
            ->map(fn (FollowUp $followUp) => [
                'id' => $followUp->id,
                'status' => $followUp->status->value,
                'type' => $followUp->type !== null ? ['id' => $followUp->type->id, 'name' => $followUp->type->name] : null,
                'due_date' => $followUp->due_date->format('Y-m-d'),
                'note' => $followUp->note,
                'completed_at' => $followUp->completed_at?->toIso8601String(),
                'completed_by' => $followUp->completedBy?->name,
                'result_note' => $followUp->result_note,
                'is_overdue' => $followUp->status === FollowUpStatus::Open && $followUp->due_date->toDateString() < $today,
            ])
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(FollowUp $followUp): void
    {
        if ($followUp->status !== FollowUpStatus::Open) {
            throw ValidationException::withMessages([
                'status' => [__('follow_up.errors.not_open')],
            ]);
        }
    }
}
