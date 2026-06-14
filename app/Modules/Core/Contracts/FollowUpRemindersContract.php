<?php

namespace App\Modules\Core\Contracts;

use App\Models\User;

/**
 * Read seam that allows DashboardController (app-level) to load due follow-ups
 * without importing the Medical module concretely.
 * FollowUpReminderService implements it; the binding lives in MedicalServiceProvider.
 */
interface FollowUpRemindersContract
{
    /**
     * Due follow-ups (follow_up_date ≤ today, clinic-local) for the active clinic.
     * Returns an empty array when the user lacks followUps.view.
     *
     * @return list<array<string, mixed>>
     */
    public function dueFor(User $user): array;
}
