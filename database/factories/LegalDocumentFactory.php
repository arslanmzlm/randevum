<?php

namespace Database\Factories;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    protected $model = LegalDocument::class;

    public function definition(): array
    {
        return [
            'clinic_id' => null, // platform-level by default
            'type' => LegalDocumentType::KvkkConsent->value,
            'version' => '1.0',
            'title' => 'KVKK Rıza ve Aydınlatma Metni',
            'content' => 'Test KVKK içeriği.',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function forClinic(int $clinicId): static
    {
        return $this->state(['clinic_id' => $clinicId]);
    }

    public function ofType(LegalDocumentType $type): static
    {
        return $this->state(['type' => $type->value]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
