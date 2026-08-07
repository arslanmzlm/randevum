<?php

namespace App\Modules\Verticals\Podiatry\Database\Seeders;

use App\Models\AnamnesisField;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

class PodiatryAnamnesisFieldsSeeder extends Seeder
{
    /**
     * Baseline anamnesis field definitions for the podiatry vertical (clinic_id null —
     * clinic-scoped overrides ship in Faz 3). Reads from the vertical config — same list
     * spirit as PodiatryAppointmentTypesSeeder. Idempotent — safe to re-run.
     */
    public function run(): void
    {
        $podiatry = Vertical::where('slug', 'podiatry')->first();

        if ($podiatry === null) {
            return;
        }

        /** @var list<array{key: string, label: string, group: string, type: string, options: mixed, sort: int, required: bool}> $defaults */
        $defaults = config('podiatry.anamnesis_fields', []);

        foreach ($defaults as $item) {
            AnamnesisField::withoutGlobalScopes()->updateOrCreate(
                ['vertical_id' => $podiatry->id, 'clinic_id' => null, 'key' => $item['key']],
                [
                    'label' => $item['label'],
                    'group' => $item['group'],
                    'type' => $item['type'],
                    'options' => $item['options'],
                    'sort' => $item['sort'],
                    'required' => $item['required'],
                    'is_active' => true,
                ],
            );
        }
    }
}
