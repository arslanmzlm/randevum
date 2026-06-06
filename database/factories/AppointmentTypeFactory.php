<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));

        return [
            'clinic_id' => Clinic::factory(),
            'vertical_id' => Vertical::factory(),
            'name' => fake()->words(2, true),
            'color' => $color,
            'default_duration_minutes' => 30,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
