<?php

namespace Database\Factories;

use App\Enums\AlcoholUse;
use App\Enums\BloodType;
use App\Enums\SmokingStatus;
use App\Models\Anamnesis;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Anamnesis>
 */
class AnamnesisFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'blood_type' => fake()->randomElement(BloodType::cases())->value,
            'height_cm' => fake()->numberBetween(150, 195),
            'weight_kg' => fake()->randomFloat(2, 50, 110),
            'smoking' => fake()->randomElement(SmokingStatus::cases())->value,
            'alcohol' => fake()->randomElement(AlcoholUse::cases())->value,
            'diabetes' => 'none',
            'hypertension' => false,
            'cardiovascular' => false,
            'respiratory' => false,
            'kidney_liver' => false,
            'thyroid' => false,
            'epilepsy' => false,
            'blood_thinners' => false,
            'bleeding_disorder' => false,
            'infectious_disease' => false,
            'infectious_disease_note' => null,
            'regular_medications' => null,
            'other_chronic' => null,
            'allergies' => null,
            'surgery_history' => null,
            'family_history' => null,
            'pregnancy' => 'none',
            'menstrual_notes' => null,
            'extra' => [],
        ];
    }
}
