<?php

namespace App\Modules\Medical\Services;

use App\Models\FollowUp;
use App\Models\User;
use App\Modules\Core\Contracts\FollowUpRemindersContract;
use App\Modules\Medical\Repositories\FollowUpRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;

class FollowUpReminderService implements FollowUpRemindersContract
{
    public function __construct(
        private FollowUpRepository $followUpRepository,
        private FollowUpTypeService $followUpTypeService,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function dueFor(User $user): array
    {
        if (! $user->can('followUps.view')) {
            return [];
        }

        $clinic = $this->clinicContext->clinicOrFail();

        // due_date is a tz-less DATE; compare against the clinic's local calendar date.
        $today = Carbon::now($clinic->timezone)->toDateString();

        return $this->followUpRepository->dueForActiveClinic($today)
            ->map(fn (FollowUp $followUp) => [
                'id' => (int) $followUp->id,
                'case_id' => $followUp->case_id,
                'patient' => [
                    'id' => (int) $followUp->patient_id,
                    'full_name' => trim($followUp->patient->first_name.' '.$followUp->patient->last_name),
                    'phone' => $followUp->patient->phone !== null ? (string) $followUp->patient->phone : null,
                    'is_deleted' => $followUp->patient->trashed(),
                ],
                'doctor' => $followUp->caseRecord !== null ? [
                    'id' => (int) $followUp->caseRecord->doctor_id,
                    'display_name' => $followUp->caseRecord->doctor->display_name,
                ] : null,
                'type' => $followUp->type !== null ? [
                    'id' => $followUp->type->id,
                    'name' => $followUp->type->name,
                ] : null,
                'due_date' => $followUp->due_date->format('Y-m-d'),
                'note' => $followUp->note,
                'is_overdue' => $followUp->due_date->toDateString() < $today,
            ])
            ->values()
            ->all();
    }

    /**
     * {@inheritdoc}
     */
    public function activeTypesFor(User $user): array
    {
        if (! $user->can('followUps.create')) {
            return [];
        }

        return $this->followUpTypeService->listActiveOptions()->all();
    }
}
