<?php

namespace Database\Seeders;

use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryAppointmentTypesSeeder;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryProductsSeeder;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryServicesSeeder;
use Illuminate\Database\Seeder;

/**
 * Local/dev demo dataset: one clinic with staff, catalog, patients and a full operational history,
 * sized so every screen has both a populated and an empty state to review.
 *
 * NOT part of DatabaseSeeder — run it explicitly:
 *
 *     ddev php artisan db:seed --class=DemoDatabaseSeeder
 *     ddev php artisan migrate:fresh --seed --seeder=DemoDatabaseSeeder   (re-centres dates on today)
 *
 * Every seeder below is idempotent, so re-running tops up what is missing instead of duplicating.
 * Date-relative data (appointments, payments) is topped up per day, which is why a re-run is the
 * cheapest way to refresh a database that has been sitting for a while.
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoSeeder::class,
            DemoStaffSeeder::class,
            // Re-run after DemoSeeder: the platform superadmin it creates is the author the legal
            // documents need, and DatabaseSeeder's earlier call is a no-op on an empty user table.
            LegalDocumentSeeder::class,
            // Catalog defaults. Real clinics get their appointment types from the registration
            // event (ProvisionDefaultAppointmentTypes); services and products are owner-entered.
            PodiatryServicesSeeder::class,
            PodiatryProductsSeeder::class,
            PodiatryAppointmentTypesSeeder::class,
            DemoAppointmentsSeeder::class,
            DemoScheduleSeeder::class,
            DemoCasesSeeder::class,
            DemoSmsLogsSeeder::class,
            DemoCrmSeeder::class,
            // Last: needs the completed treatments DemoCasesSeeder produces.
            DemoBillingSeeder::class,
            DemoTreatmentMediaSeeder::class,
        ]);
    }
}
