<?php

namespace App\Models;

use App\Enums\ClinicRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
        ];
    }

    // Deliberately NOT BelongsToClinic. A ClinicScope on `roles` would hide the global
    // baseline rows (clinic_id null) from Spatie's own role/permission lookups and break
    // every can() check in the app — global rows must stay visible to every query.

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function isCustomized(): bool
    {
        return $this->clinic_id !== null;
    }

    /** A clinic-owned role whose name is not one of the baseline ClinicRole cases. */
    public function isCustomRole(): bool
    {
        return $this->clinic_id !== null && ClinicRole::tryFrom($this->name) === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('clinic_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForClinic(Builder $query, int $clinicId): Builder
    {
        return $query->where('clinic_id', $clinicId);
    }
}
