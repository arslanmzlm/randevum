<?php

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Models\Clinic;
use App\Models\FollowUp;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'case_id' => null,
            'follow_up_type_id' => null,
            'due_date' => now()->toDateString(),
            'note' => fake()->sentence(),
            'status' => FollowUpStatus::Open,
            'assigned_to_user_id' => null,
            'created_by_user_id' => null,
            'completed_by_user_id' => null,
            'completed_at' => null,
            'result_note' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => FollowUpStatus::Open]);
    }

    public function done(): static
    {
        return $this->state([
            'status' => FollowUpStatus::Done,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => FollowUpStatus::Cancelled]);
    }

    public function overdue(): static
    {
        return $this->state(['due_date' => now()->subDays(3)->toDateString()]);
    }
}
