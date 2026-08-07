<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\FollowUpType;
use Illuminate\Database\Seeder;

/**
 * Idempotently provisions the platform default follow-up types into every existing clinic —
 * the ClinicRegistered listener only covers NEW clinics from this point forward.
 *
 * Uses the Model directly (PodiatryAppointmentTypesSeeder precedent) rather than
 * FollowUpTypeService: a seeder sits outside every module, so importing a module's concrete
 * Service would violate the "services stay internal" architecture rule.
 */
class FollowUpTypeSeeder extends Seeder
{
    public function run(): void
    {
        $names = config('platform.follow_ups.default_types', []);

        if (empty($names)) {
            return;
        }

        foreach (Clinic::withoutGlobalScopes()->get() as $clinic) {
            foreach ($names as $name) {
                FollowUpType::withoutGlobalScopes()->firstOrCreate(
                    ['clinic_id' => $clinic->id, 'name' => $name],
                    ['is_system' => true, 'is_active' => true],
                );
            }
        }
    }
}
