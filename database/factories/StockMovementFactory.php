<?php

namespace Database\Factories;

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 20) * fake()->randomElement([1, -1]);

        return [
            'clinic_id' => Clinic::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'balance_after' => fake()->numberBetween(0, 200),
            'reason' => fake()->randomElement(StockMovementReason::cases()),
            'treatment_id' => null,
            'note' => fake()->optional()->sentence(),
            'created_by' => null,
        ];
    }

    public function treatmentUsage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => StockMovementReason::TreatmentUsage,
            'quantity' => -abs($attributes['quantity']),
        ]);
    }

    public function initial(): static
    {
        return $this->state(['reason' => StockMovementReason::Initial]);
    }
}
