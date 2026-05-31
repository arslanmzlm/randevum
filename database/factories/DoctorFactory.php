<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clinic_id' => Clinic::factory(),
            'title' => fake()->optional()->randomElement(['Dr.', 'Prof. Dr.', 'Doç. Dr.', 'Op. Dr.']),
            'specialization' => fake()->optional()->randomElement([
                'Podoloji', 'Ortopedi', 'Dermatoloji', 'Fizyoterapi',
            ]),
            'bio' => fake()->optional()->paragraph(),
            'license_number' => fake()->optional()->numerify('TR-#####'),
            'certificate' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
