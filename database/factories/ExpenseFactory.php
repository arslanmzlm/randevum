<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'expense_date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, 20, 2000),
            'category' => fake()->optional()->randomElement(['Kira', 'Fatura', 'Malzeme', 'Personel', 'Diğer']),
            'description' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
