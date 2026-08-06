<?php

namespace App\Modules\Medical\Services;

use App\Models\CaseRecord;
use App\Models\User;
use App\Modules\Core\Contracts\FollowUpRemindersContract;
use App\Modules\Medical\Repositories\CaseRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;

class FollowUpReminderService implements FollowUpRemindersContract
{
    public function __construct(
        private CaseRepository $caseRepository,
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

        // follow_up_date is a tz-less DATE; compare against the clinic's local calendar date.
        $today = Carbon::now($clinic->timezone)->toDateString();

        return $this->caseRepository->dueFollowUps($today)
            ->map(fn (CaseRecord $case) => [
                'case_id' => (int) $case->id,
                'patient' => [
                    'id' => (int) $case->patient_id,
                    'full_name' => trim($case->patient->first_name.' '.$case->patient->last_name),
                    'phone' => $case->patient->phone !== null ? (string) $case->patient->phone : null,
                ],
                'doctor' => [
                    'id' => (int) $case->doctor_id,
                    'display_name' => $case->doctor->display_name,
                ],
                'follow_up_date' => $case->follow_up_date->format('Y-m-d'),
                'follow_up_note' => $case->follow_up_note,
                'is_overdue' => $case->follow_up_date->toDateString() < $today,
            ])
            ->values()
            ->all();
    }
}
