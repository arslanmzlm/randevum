<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\PatientSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientSegment>
 */
class PatientSegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => fake()->words(2, true),
            'criteria' => ['is_legacy' => true],
        ];
    }
}
