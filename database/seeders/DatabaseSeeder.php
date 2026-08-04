<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data every environment needs: roles, permissions, reference tables, verticals and the
     * platform legal documents. All of it is idempotent — re-running heals drift and never
     * duplicates a row.
     *
     * Demo/dev fixtures deliberately live outside this chain, in DemoDatabaseSeeder:
     *
     *     ddev php artisan db:seed --class=DemoDatabaseSeeder
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            CountrySeeder::class,
            CitySeeder::class,
            VerticalSeeder::class,
            // Skips silently while no platform superadmin exists to author the documents;
            // DemoDatabaseSeeder calls it again once it has created one.
            LegalDocumentSeeder::class,
        ]);
    }
}
