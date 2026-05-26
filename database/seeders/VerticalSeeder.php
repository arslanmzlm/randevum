<?php

namespace Database\Seeders;

use App\Models\Vertical;
use Illuminate\Database\Seeder;

class VerticalSeeder extends Seeder
{
    /**
     * DB-driven vertical list. MVP: podiatry. Display name/terminology come from
     * the vertical module's lang files, not DB columns.
     */
    public function run(): void
    {
        Vertical::firstOrCreate(
            ['slug' => 'podiatry'],
            ['config' => null, 'is_active' => true],
        );
    }
}
