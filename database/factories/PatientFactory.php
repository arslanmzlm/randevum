<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'user_id' => null, // MVP'de hep null
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->unique()->numerify('053# ### ## ##'),
            'contact_phone' => null,
            'email' => fake()->optional()->safeEmail(),
            'birth_date' => fake()->optional()->date(),
            'gender' => fake()->randomElement([Gender::Male, Gender::Female, Gender::Other, null]),
            'notification_enabled' => true,
            'is_legacy' => false,
            'notes' => null,
        ];
    }

    public function legacy(): static
    {
        return $this->state(['is_legacy' => true]);
    }

    public function trashed(): static
    {
        return $this->state(['deleted_at' => now()]);
    }
}
