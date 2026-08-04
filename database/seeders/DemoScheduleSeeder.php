<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Doctor leave for the demo clinic so the availability screen has rows and the calendar renders
 * its leave bands: an upcoming all-day block, a half-day, a multi-day holiday and a past one
 * (the list's past/upcoming split).
 *
 * Runs AFTER DemoSeeder (needs the clinic's doctors).
 */
class DemoScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();
        $owner = User::where('email', 'owner@podosen.test')->first();

        if (! $clinic || ! $owner) {
            return;
        }

        if (ScheduleException::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists()) {
            return;
        }

        $doctors = Doctor::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->take(3)
            ->get();

        if ($doctors->isEmpty()) {
            return;
        }

        $timezone = $clinic->timezone;
        $today = Carbon::today($timezone);

        // [doctorIndex, startOffsetDays, startHour, endOffsetDays, endHour, allDay, reason]
        $rows = [
            [0, 3, 0, 3, 24, true, 'İzin'],
            [0, 10, 13, 10, 18, false, 'Seminer'],
            [1, 5, 0, 8, 24, true, 'Yıllık izin'],
            [1, 1, 9, 1, 12, false, 'Kişisel'],
            [2, -12, 0, -11, 24, true, 'Hastalık'],
            [2, 14, 15, 14, 18, false, 'Kongre'],
        ];

        foreach ($rows as [$doctorIndex, $startOffset, $startHour, $endOffset, $endHour, $allDay, $reason]) {
            $doctor = $doctors[$doctorIndex] ?? null;

            if (! $doctor) {
                continue;
            }

            $startsAt = $today->copy()->addDays($startOffset)->addHours($startHour);
            $endsAt = $today->copy()->addDays($endOffset)->addHours($endHour);

            ScheduleException::create([
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor->id,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'is_all_day' => $allDay,
                'reason' => $reason,
                'created_by' => $owner->id,
            ]);
        }
    }
}
