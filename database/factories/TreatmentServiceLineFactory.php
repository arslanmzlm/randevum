<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Treatment;
use App\Models\TreatmentServiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TreatmentServiceLine>
 */
class TreatmentServiceLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 50, 500);
        $qty = fake()->numberBetween(1, 3);
        $discount = 0;
        $subtotal = max(0, ($qty * $unitPrice) - $discount);

        return [
            'treatment_id' => Treatment::factory(),
            // Derived, never independent: a line always belongs to its treatment's clinic.
            'clinic_id' => fn (array $attributes): int => Treatment::withoutGlobalScopes()
                ->findOrFail($attributes['treatment_id'])->clinic_id,
            'service_id' => Service::factory(),
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'discount_amount' => $discount,
            'subtotal' => $subtotal,
            'note' => null,
            'sort_order' => 0,
        ];
    }
}
