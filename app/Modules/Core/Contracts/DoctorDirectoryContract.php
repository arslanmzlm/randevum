<?php

namespace App\Modules\Core\Contracts;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-module read-only directory for doctors in the current clinic.
 *
 * Lives in Core (shared kernel) so Scheduling and other modules can depend on
 * it without creating a direct sibling-module import. The binding from contract
 * to implementation lives in CoreServiceProvider.
 */
interface DoctorDirectoryContract
{
    /**
     * All active (is_active=true) doctors in the current clinic, with user loaded.
     *
     * ClinicScope on Doctor already filters to the active clinic.
     *
     * @return Collection<int, Doctor>
     */
    public function activeForClinic(): Collection;

    /**
     * Find a specific doctor in the current clinic by their profile id, or null.
     *
     * ClinicScope ensures the lookup is constrained to the active clinic.
     * Returns inactive doctors too — use activeForClinic() for active-only lists.
     */
    public function findForClinic(int $doctorId): ?Doctor;
}
