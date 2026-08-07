<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\FollowUpType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUpType>
 */
class FollowUpTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => fake()->words(2, true),
            'is_system' => false,
            'is_active' => true,
        ];
    }

    public function system(): static
    {
        return $this->state(['is_system' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
