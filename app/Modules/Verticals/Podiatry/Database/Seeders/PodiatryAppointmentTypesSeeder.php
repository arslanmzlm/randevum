<?php

namespace App\Modules\Verticals\Podiatry\Database\Seeders;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

class PodiatryAppointmentTypesSeeder extends Seeder
{
    /**
     * Default appointment types for podiatry clinics.
     * Reads from the vertical config — same list the runtime listener uses (no duplication).
     * Idempotent — safe to re-run.
     */
    public function run(): void
    {
        $podiatry = Vertical::where('slug', 'podiatry')->first();

        if ($podiatry === null) {
            return;
        }

        /** @var array{name: string, color: string, default_duration_minutes: int}[] $defaults */
        $defaults = config('podiatry.appointment_types', []);

        if (empty($defaults)) {
            return;
        }

        $clinics = Clinic::where('vertical_id', $podiatry->id)->get();

        foreach ($clinics as $clinic) {
            foreach ($defaults as $item) {
                AppointmentType::withoutGlobalScopes()->firstOrCreate(
                    ['clinic_id' => $clinic->id, 'name' => $item['name']],
                    array_merge($item, [
                        'vertical_id' => $podiatry->id,
                        'is_active' => true,
                    ]),
                );
            }
        }
    }
}
