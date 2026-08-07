<?php

namespace App\Modules\Core\Contracts;

use Illuminate\Support\Collection;

/**
 * Read seam for Scheduling's appointment-type catalog used by other modules (treatment
 * follow-up appointment-type select). Lives in Core (shared kernel) so Medical can read
 * active appointment types without importing Scheduling's model/repository concretely.
 * AppointmentTypeService implements it; the binding lives in SchedulingServiceProvider.
 */
interface AppointmentTypeLookupContract
{
    /**
     * Active appointment types for the active clinic, projected for a select control,
     * ordered by name.
     *
     * @return Collection<int, array{id: int, name: string, color: string|null}>
     */
    public function activeForTreatment(): Collection;
}
