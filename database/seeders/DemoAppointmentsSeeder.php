<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * A small, realistic booking load for the demo clinic's doctors so the create-appointment
 * day panel / availability check (and the upcoming calendar) have something to show: a tight
 * near-date window, in-hours non-overlapping slots, 2–5 per doctor per day, drawn from the
 * existing 100 patients (no new ones) and the clinic's real services.
 *
 * Runs AFTER DemoSeeder (clinic/doctors/patients) AND the vertical service seeders, so
 * appointments can reference real services — hence its own seeder, last in DatabaseSeeder.
 */
class DemoAppointmentsSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();
        $owner = User::where('email', 'owner@podosen.test')->first();

        if (! $clinic || ! $owner) {
            return;
        }

        // Idempotent top-up: skip once a realistic set exists, so re-seeds don't pile up and
        // any appointments booked by hand while testing survive.
        if (Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count() >= 10) {
            return;
        }

        $doctorIds = Doctor::withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('id')->all();
        $patientIds = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->pluck('id')->all();
        $serviceIds = Service::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('is_active', true)->pluck('id')->all();

        if ($doctorIds === [] || $patientIds === []) {
            return;
        }

        $hours = $clinic->working_hours;
        $today = Carbon::today($clinic->timezone);

        for ($offset = -2; $offset <= 10; $offset++) {
            $day = $today->copy()->addDays($offset);
            $cfg = $hours[strtolower($day->englishDayOfWeek)] ?? null;

            if (! $cfg || ($cfg['closed'] ?? false)) {
                continue;
            }

            $open = $this->toMinutes($cfg['open']);
            $close = $this->toMinutes($cfg['close']);
            $breakStart = isset($cfg['break'][0]) ? $this->toMinutes($cfg['break'][0]) : null;
            $breakEnd = isset($cfg['break'][1]) ? $this->toMinutes($cfg['break'][1]) : null;

            foreach ($doctorIds as $doctorId) {
                foreach ($this->pickSlots($open, $close, $breakStart, $breakEnd) as [$startMin, $duration]) {
                    $startLocal = $day->copy()->addMinutes($startMin);

                    Appointment::factory()->create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => fake()->randomElement($patientIds),
                        'doctor_id' => $doctorId,
                        // Most visits have a stated service; leave some null to show both panel states.
                        'service_id' => ($serviceIds !== [] && fake()->boolean(80)) ? fake()->randomElement($serviceIds) : null,
                        'starts_at' => $startLocal->copy()->utc(),
                        'ends_at' => $startLocal->copy()->addMinutes($duration)->utc(),
                        'status' => $this->demoStatus($day),
                        'is_walk_in' => fake()->boolean(12),
                        'created_by' => $owner->id,
                    ]);
                }
            }
        }
    }

    /**
     * Pick 2–5 non-overlapping in-hours slots (start-minute + duration) for one doctor/day,
     * never straddling the lunch break.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function pickSlots(int $open, int $close, ?int $breakStart, ?int $breakEnd): array
    {
        $durations = [30, 30, 45, 60];
        $target = random_int(2, 5);

        $grid = [];
        for ($m = $open; $m + 30 <= $close; $m += 30) {
            $grid[] = $m;
        }
        shuffle($grid);

        $picked = [];

        foreach ($grid as $start) {
            if (count($picked) >= $target) {
                break;
            }

            $duration = $durations[array_rand($durations)];
            $end = $start + $duration;

            if ($end > $close) {
                continue;
            }

            if ($breakStart !== null && $start < $breakEnd && $end > $breakStart) {
                continue;
            }

            foreach ($picked as [$pickedStart, $pickedDuration]) {
                if ($start < $pickedStart + $pickedDuration && $end > $pickedStart) {
                    continue 2;
                }
            }

            $picked[] = [$start, $duration];
        }

        return $picked;
    }

    /**
     * A plausible, varied status for the day: past days are wrapped up (completed, the odd
     * cancellation / no-show), today is in-progress, future days are still on the books with the
     * occasional reschedule or cancellation. Weighted by repetition; spans the MVP statuses (which
     * the calendar shows) so the demo isn't a wall of one colour.
     */
    private function demoStatus(Carbon $day): AppointmentStatus
    {
        return match (true) {
            $day->isToday() => fake()->randomElement([
                AppointmentStatus::Arrived,
                AppointmentStatus::Confirmed,
                AppointmentStatus::Confirmed,
                AppointmentStatus::Completed,
                AppointmentStatus::Cancelled,
            ]),
            $day->isPast() => fake()->randomElement([
                AppointmentStatus::Completed,
                AppointmentStatus::Completed,
                AppointmentStatus::Completed,
                AppointmentStatus::Cancelled,
                AppointmentStatus::NoShow,
            ]),
            default => fake()->randomElement([
                AppointmentStatus::Confirmed,
                AppointmentStatus::Confirmed,
                AppointmentStatus::Confirmed,
                AppointmentStatus::Rescheduled,
                AppointmentStatus::Cancelled,
            ]),
        };
    }

    private function toMinutes(string $time): int
    {
        [$hour, $minute] = explode(':', $time);

        return (int) $hour * 60 + (int) $minute;
    }
}
