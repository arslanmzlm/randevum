<?php

namespace Database\Factories;

use App\Enums\CaseStatus;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseRecord>
 */
class CaseRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'vertical_id' => Vertical::factory(),
            'title' => fake()->sentence(3),
            'status' => CaseStatus::Open,
            'notes' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'suspended_at' => null,
            'follow_up_date' => null,
            'follow_up_note' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => CaseStatus::Open, 'closed_at' => null, 'suspended_at' => null]);
    }

    public function closed(): static
    {
        return $this->state(['status' => CaseStatus::Closed, 'closed_at' => now()]);
    }
}
