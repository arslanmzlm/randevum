<?php

namespace App\Modules\Medical\Contracts;

use App\Models\User;

/**
 * Read seam that allows DashboardController (app-level) to load due follow-ups
 * (and the type options for the widget's create dialog) without importing the
 * Medical module concretely.
 * FollowUpReminderService implements it; the binding lives in MedicalServiceProvider.
 */
interface FollowUpRemindersContract
{
    /**
     * Due follow-ups (due_date ≤ today, clinic-local, status = open) for the active clinic.
     * Returns an empty array when the user lacks followUps.view.
     *
     * @return list<array<string, mixed>>
     */
    public function dueFor(User $user): array;

    /**
     * Active follow-up types for the dashboard widget's create-follow-up dialog.
     * Returns an empty array when the user lacks followUps.create.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeTypesFor(User $user): array;
}
