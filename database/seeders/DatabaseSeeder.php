<?php

namespace Database\Seeders;

use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryProductsSeeder;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryServicesSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            CountrySeeder::class,
            CitySeeder::class,
            VerticalSeeder::class,
            DemoSeeder::class,
            PodiatryServicesSeeder::class,
            PodiatryProductsSeeder::class,
            // Last: needs the demo clinic/doctors/patients AND the vertical's services to exist.
            DemoAppointmentsSeeder::class,
        ]);
    }
}
