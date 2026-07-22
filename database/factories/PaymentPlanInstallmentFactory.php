<?php

namespace Database\Factories;

use App\Enums\InstallmentStatus;
use App\Models\Clinic;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlanInstallment>
 */
class PaymentPlanInstallmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'payment_plan_id' => PaymentPlan::factory(),
            'sequence' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => fake()->randomFloat(2, 50, 500),
            'status' => InstallmentStatus::Pending,
            'paid_at' => null,
            'reminder_7d_sent' => false,
            'reminder_1d_sent' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => InstallmentStatus::Cancelled]);
    }
}
