<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+3 months');
        $endsAt = fake()->dateTimeBetween($startsAt, (clone $startsAt)->modify('+8 hours'));

        return [
            'clinic_id' => Clinic::factory(),
            'doctor_id' => Doctor::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_all_day' => false,
            'reason' => fake()->optional()->randomElement(['İzin', 'Seminer', 'Hastalık', 'Kişisel']),
            'created_by' => null,
        ];
    }

    public function allDay(): static
    {
        return $this->state(function (): array {
            $date = fake()->dateTimeBetween('now', '+3 months');
            $start = (clone $date)->setTime(0, 0, 0);
            $end = (clone $date)->setTime(23, 59, 59);

            return [
                'starts_at' => $start,
                'ends_at' => $end,
                'is_all_day' => true,
            ];
        });
    }

    public function past(): static
    {
        return $this->state(function (): array {
            $endsAt = fake()->dateTimeBetween('-2 months', '-1 day');
            $startsAt = fake()->dateTimeBetween('-3 months', $endsAt);

            return [
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        });
    }

    public function createdBy(User $user): static
    {
        return $this->state(['created_by' => $user->id]);
    }
}
