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
        // The reason picks the sign — a factory row must obey the same direction rule the
        // service enforces, or fixtures start describing movements that can't happen.
        $reason = fake()->randomElement(StockMovementReason::cases());
        $sign = $reason->sign() ?? fake()->randomElement([1, -1]);

        return [
            'clinic_id' => Clinic::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 20) * $sign,
            'balance_after' => fake()->numberBetween(0, 200),
            'reason' => $reason,
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
