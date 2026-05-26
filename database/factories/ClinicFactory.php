<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Clinic;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = Country::firstOrCreate(['code' => 'TR'], ['name' => 'Türkiye', 'is_active' => true]);
        $city = City::firstOrCreate(
            ['country_id' => $country->id, 'code' => '35'],
            ['name' => 'İzmir', 'is_active' => true],
        );

        $name = fake()->company().' Kliniği';

        return [
            'tenant_id' => Tenant::factory(),
            'vertical_id' => Vertical::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'phone' => '0232 '.fake()->numerify('### ## ##'), // valid İzmir landline (laravel-phone requires a valid number)
            'country_id' => $country->id,
            'city_id' => $city->id,
            'address' => fake()->streetAddress(),
            'working_hours' => Clinic::defaultWorkingHours(),
            'default_slot_duration_minutes' => 30,
            'timezone' => 'Europe/Istanbul',
            'locale' => 'tr_TR',
            'currency' => 'TRY',
            'is_active' => true,
            'onboarded_at' => now(),
        ];
    }
}
