<?php

namespace Database\Factories;

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Treatment>
 */
class TreatmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $detail = PodiatryTreatmentDetail::create([]);

        return [
            'clinic_id' => Clinic::factory(),
            'appointment_id' => Appointment::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'case_id' => null,
            'details_type' => 'podiatry',
            'details_id' => $detail->id,
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'notes' => null,
            'status' => TreatmentStatus::Draft,
            'completed_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => TreatmentStatus::Draft, 'completed_at' => null]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => TreatmentStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
