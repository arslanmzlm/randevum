<?php

namespace App\Modules\Identity\Support;

use App\Models\Clinic;
use Illuminate\Support\Str;

/**
 * Slug uniqueness across every clinic, regardless of the active ClinicScope — an
 * active clinic context must never hide a slug collision from another clinic.
 */
class ClinicSlug
{
    public static function unique(string $clinicName): string
    {
        $base = Str::slug($clinicName);
        $slug = $base;
        $suffix = 2;

        while (Clinic::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
