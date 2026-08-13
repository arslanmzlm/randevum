<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Contracts\PatientAppointmentCounterContract;
use App\Modules\Scheduling\Repositories\AppointmentRepository;

/**
 * Exists only to keep the resolution chain short: AppointmentService cannot serve the
 * deletion guard without pulling Medical back in through PatientRegistrarContract.
 * Never add a dependency beyond the repository here.
 */
class PatientAppointmentCounter implements PatientAppointmentCounterContract
{
    public function __construct(
        private AppointmentRepository $repository,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function countFutureForPatient(int $patientId): int
    {
        return $this->repository->countFutureForPatient($patientId);
    }
}
