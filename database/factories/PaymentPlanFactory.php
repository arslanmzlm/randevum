<?php

namespace Database\Factories;

use App\Enums\PaymentPlanStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'treatment_id' => null,
            'total_amount' => fake()->randomFloat(2, 300, 3000),
            'down_payment' => null,
            'installment_count' => 3,
            'status' => PaymentPlanStatus::Active,
            'created_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => PaymentPlanStatus::Completed]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => PaymentPlanStatus::Cancelled]);
    }
}
