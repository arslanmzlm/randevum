<?php

namespace App\Modules\Core\Services;

use App\Models\Doctor;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Repositories\DoctorRepository;
use Illuminate\Database\Eloquent\Collection;

class DoctorDirectoryService implements DoctorDirectoryContract
{
    public function __construct(private DoctorRepository $repository) {}

    /**
     * @return Collection<int, Doctor>
     */
    public function activeForClinic(): Collection
    {
        return $this->repository->activeForClinic();
    }

    /**
     * @return Collection<int, Doctor>
     */
    public function forCalendarFilter(): Collection
    {
        return $this->repository->forCalendarFilter();
    }

    public function findForClinic(int $doctorId): ?Doctor
    {
        return $this->repository->findForClinic($doctorId);
    }
}
