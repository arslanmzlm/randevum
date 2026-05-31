<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Product;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'vertical_id' => Vertical::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'brand' => fake()->optional()->company(),
            'category' => fake()->optional()->word(),
            'sku' => fake()->optional()->bothify('SKU-####'),
            'unit' => 'adet',
            'price' => fake()->randomFloat(2, 10, 500),
            'current_stock' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function negativeStock(): static
    {
        return $this->state(['current_stock' => fake()->numberBetween(-20, -1)]);
    }
}
