<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * ISO 3166-1 alpha-2. MVP: only Türkiye; add more when going international.
     */
    public function run(): void
    {
        Country::firstOrCreate(
            ['code' => 'TR'],
            ['name' => 'Türkiye', 'is_active' => true],
        );
    }
}
