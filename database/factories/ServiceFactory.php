<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Service;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'vertical_id' => Vertical::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 50, 1500),
            'duration_minutes' => null,
            'default_complaint' => null,
            'default_diagnosis' => null,
            'default_treatment_process' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
