<?php

namespace App\Modules\Medical\Repositories;

use App\Models\AnamnesisField;
use Illuminate\Database\Eloquent\Collection;

class AnamnesisFieldRepository
{
    /**
     * Active definitions for a vertical (vertical-wide + this clinic's overrides), sort order.
     * Platform-row exception (multi-tenancy guideline): AnamnesisField carries a nullable
     * clinic_id, so scopes are bypassed and the clinic predicate is applied explicitly here.
     *
     * @return Collection<int, AnamnesisField>
     */
    public function activeForVertical(int $verticalId, ?int $clinicId): Collection
    {
        return AnamnesisField::withoutGlobalScopes()
            ->where('vertical_id', $verticalId)
            ->where(fn ($query) => $query->whereNull('clinic_id')->orWhere('clinic_id', $clinicId))
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * All definitions for a vertical, active or not — the PDF still prints a retired
     * field's stored value.
     *
     * @return Collection<int, AnamnesisField>
     */
    public function allForVertical(int $verticalId, ?int $clinicId): Collection
    {
        return AnamnesisField::withoutGlobalScopes()
            ->where('vertical_id', $verticalId)
            ->where(fn ($query) => $query->whereNull('clinic_id')->orWhere('clinic_id', $clinicId))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }
}
