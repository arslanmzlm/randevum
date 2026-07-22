<?php

namespace Database\Factories;

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSmsSetting>
 */
class ClinicSmsSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'sms_type' => $this->faker->randomElement(SmsType::clinicScopedCases()),
            'enabled' => true,
            'template' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    public function forType(SmsType $type): static
    {
        return $this->state(['sms_type' => $type]);
    }

    public function withTemplate(string $template): static
    {
        return $this->state(['template' => $template]);
    }
}
