<?php

namespace App\Modules\Core\Services;

use App\Models\Doctor;
use App\Modules\Core\Contracts\DoctorLockContract;
use App\Modules\Core\Repositories\DoctorRepository;

class DoctorLockingService implements DoctorLockContract
{
    public function __construct(private DoctorRepository $repository) {}

    public function lockForUpdate(int $doctorId): ?Doctor
    {
        return $this->repository->lockForUpdate($doctorId);
    }
}
