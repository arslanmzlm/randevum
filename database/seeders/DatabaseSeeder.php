<?php

namespace Database\Seeders;

use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryAnamnesisFieldsSeeder;
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
            // Baseline data, not demo data: definitions must exist for every environment,
            // including tests.
            PodiatryAnamnesisFieldsSeeder::class,
            // Idempotent backfill for clinics that predate the ClinicRegistered provisioning
            // listener; no-op (firstOrCreate) for clinics that already have their types.
            FollowUpTypeSeeder::class,
            // Skips silently while no platform superadmin exists to author the documents;
            // DemoDatabaseSeeder calls it again once it has created one.
            LegalDocumentSeeder::class,
        ]);
    }
}
