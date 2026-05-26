<?php

namespace Database\Factories;

use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vertical>
 */
class VerticalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(1),
            'config' => null,
            'is_active' => true,
        ];
    }
}
