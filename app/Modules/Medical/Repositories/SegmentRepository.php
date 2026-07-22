<?php

namespace App\Modules\Medical\Repositories;

use App\Models\PatientSegment;
use Illuminate\Database\Eloquent\Collection;

class SegmentRepository
{
    /**
     * All saved segments for the active clinic, name-ordered. Segments are clinic-shared
     * (no owning user), so every list viewer sees the same set. ClinicScope on
     * PatientSegment restricts results to the active clinic.
     *
     * @return Collection<int, PatientSegment>
     */
    public function allForActiveClinic(): Collection
    {
        return PatientSegment::orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PatientSegment
    {
        return PatientSegment::create($data);
    }

    public function delete(PatientSegment $segment): void
    {
        $segment->delete();
    }
}
