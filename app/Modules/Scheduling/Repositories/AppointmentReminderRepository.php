<?php

namespace App\Modules\Scheduling\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Scopes\ClinicScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class AppointmentReminderRepository
{
    /**
     * Appointments whose starts_at falls in [fromUtc, toUtc) that are eligible for
     * a reminder: status Confirmed or Rescheduled, the matching flag = false.
     *
     * Runs without ClinicScope — the cron processes all clinics globally. Each
     * returned appointment carries its own clinic_id so the gate is correctly scoped.
     * Patient and clinic are eager-loaded to avoid N+1.
     *
     * @param  non-empty-string  $flagColumn  'reminder_24h_sent' or 'reminder_1h_sent'
     * @return Collection<int, Appointment>
     */
    public function dueForReminder(CarbonInterface $fromUtc, CarbonInterface $toUtc, string $flagColumn): Collection
    {
        return Appointment::withoutGlobalScope(ClinicScope::class)
            ->startingBetween($fromUtc, $toUtc)
            ->withStatus([AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled])
            ->where($flagColumn, false)
            ->with(['patient', 'clinic'])
            ->get();
    }

    public function markReminderSent(Appointment $appointment, string $flagColumn): void
    {
        $appointment->$flagColumn = true;
        $appointment->save();
    }
}
