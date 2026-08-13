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
     * Patient, clinic and doctor.user are eager-loaded to avoid N+1 (doctor.user
     * feeds the ':doctor' template variable). The clinic-owned relations drop the scope
     * too: the cron has no active clinic and ClinicScope is fail-closed, so leaving them
     * scoped would null out every patient/doctor and crash the message builder.
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
            ->with([
                'patient' => fn ($query) => $query->withoutGlobalScope(ClinicScope::class),
                'clinic',
                'doctor' => fn ($query) => $query->withoutGlobalScope(ClinicScope::class)->with('user'),
            ])
            ->get();
    }

    public function markReminderSent(Appointment $appointment, string $flagColumn): void
    {
        $appointment->$flagColumn = true;
        $appointment->save();
    }
}
