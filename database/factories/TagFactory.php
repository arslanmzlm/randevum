<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => fake()->unique()->word(),
            'color' => sprintf('#%06X', mt_rand(0, 0xFFFFFF)),
        ];
    }
}
