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
}
