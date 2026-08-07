<?php

namespace Database\Factories;

use App\Enums\AnamnesisFieldType;
use App\Models\AnamnesisField;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnamnesisField>
 */
class AnamnesisFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vertical_id' => Vertical::factory(),
            'clinic_id' => null,
            'key' => fake()->unique()->slug(2, '_'),
            'label' => 'health.fields.'.fake()->word(),
            'group' => 'health.groups.general',
            'type' => AnamnesisFieldType::Text,
            'options' => null,
            'sort' => fake()->numberBetween(0, 100),
            'required' => false,
            'is_active' => true,
        ];
    }

    public function text(): static
    {
        return $this->state(['type' => AnamnesisFieldType::Text, 'options' => null]);
    }

    public function boolean(): static
    {
        return $this->state(['type' => AnamnesisFieldType::Boolean, 'options' => null]);
    }

    public function select(): static
    {
        return $this->state([
            'type' => AnamnesisFieldType::Select,
            'options' => [
                ['value' => 'a', 'label' => 'health.options.demo.a'],
                ['value' => 'b', 'label' => 'health.options.demo.b'],
            ],
        ]);
    }

    public function multiselect(): static
    {
        return $this->state([
            'type' => AnamnesisFieldType::Multiselect,
            'options' => [
                ['value' => 'a', 'label' => 'health.options.demo.a'],
                ['value' => 'b', 'label' => 'health.options.demo.b'],
            ],
        ]);
    }

    public function number(): static
    {
        return $this->state(['type' => AnamnesisFieldType::Number, 'options' => null]);
    }

    public function date(): static
    {
        return $this->state(['type' => AnamnesisFieldType::Date, 'options' => null]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
