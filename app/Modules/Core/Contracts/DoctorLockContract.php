<?php

namespace App\Modules\Core\Contracts;

use App\Models\Doctor;

/**
 * Serializes doctor-scoped writes (e.g. appointment booking) via a row lock.
 *
 * Lives in Core (shared kernel) so Scheduling can lock the doctor row for the duration of a
 * transaction without importing Core's DoctorRepository concretely. DoctorLockingService
 * implements it; the binding lives in CoreServiceProvider.
 */
interface DoctorLockContract
{
    /**
     * Row-lock the doctor for the rest of the current transaction.
     * Must be called from inside an outer DB::transaction — no inner transaction.
     * SQLite compiles the lock away, so the test suite is unaffected.
     */
    public function lockForUpdate(int $doctorId): ?Doctor;
}
