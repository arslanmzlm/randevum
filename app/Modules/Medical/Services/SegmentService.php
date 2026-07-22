<?php

namespace App\Modules\Medical\Services;

use App\Models\PatientSegment;
use App\Modules\Medical\Repositories\SegmentRepository;
use Illuminate\Database\Eloquent\Collection;

class SegmentService
{
    public function __construct(
        private SegmentRepository $repository,
    ) {}

    /**
     * @return Collection<int, PatientSegment>
     */
    public function listForActiveClinic(): Collection
    {
        return $this->repository->allForActiveClinic();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PatientSegment
    {
        // clinic_id is auto-set by BelongsToClinic on create.
        return $this->repository->create($data);
    }

    public function delete(PatientSegment $segment): void
    {
        $this->repository->delete($segment);
    }
}
