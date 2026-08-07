<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 50, 1000),
            'payment_method' => fake()->randomElement(PaymentMethod::cases()),
            'status' => TransactionStatus::Completed,
            'paid_at' => now(),
            'note' => null,
            'created_by' => null,
        ];
    }

    /** Manual income (owner brief): clinic income with no patient, patient_id/treatment_id NULL. */
    public function manual(): static
    {
        return $this->state(fn (): array => [
            'patient_id' => null,
            'treatment_id' => null,
            'category' => fake()->word(),
        ]);
    }
}
