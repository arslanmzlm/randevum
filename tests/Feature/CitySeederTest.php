<?php

use App\Models\City;
use App\Models\Country;
use Database\Seeders\CitySeeder;
use Database\Seeders\CountrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds coordinates for all 81 Turkish provinces', function (): void {
    $this->seed([CountrySeeder::class, CitySeeder::class]);

    expect(City::count())->toBe(81)
        ->and(City::whereNull('latitude')->orWhereNull('longitude')->count())->toBe(0);

    $izmir = City::where('code', '35')->firstOrFail();
    expect((float) $izmir->latitude)->toBeGreaterThan(38.0)->toBeLessThan(39.0)
        ->and((float) $izmir->longitude)->toBeGreaterThan(26.0)->toBeLessThan(28.0);
});

it('re-running the seeder heals a city row that predates coordinates', function (): void {
    $this->seed(CountrySeeder::class);
    $turkey = Country::where('code', 'TR')->firstOrFail();

    City::create([
        'country_id' => $turkey->id,
        'code' => '06',
        'name' => 'Ankara',
        'is_active' => true,
    ]);

    $this->seed(CitySeeder::class);

    $ankara = City::where('code', '06')->firstOrFail();
    expect($ankara->latitude)->not->toBeNull()
        ->and($ankara->longitude)->not->toBeNull();
});
