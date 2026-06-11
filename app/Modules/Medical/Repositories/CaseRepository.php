<?php

namespace App\Modules\Medical\Repositories;

use App\Models\CaseRecord;
use Illuminate\Database\Eloquent\Collection;

class CaseRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CaseRecord
    {
        return CaseRecord::create($data);
    }

    /**
     * Open cases for a specific patient + doctor in the active clinic, with treatment counts.
     * Used to populate the "Açık vakadan seç" dropdown on the Process screen.
     *
     * ClinicScope on CaseRecord isolates to the active clinic automatically.
     *
     * @return Collection<int, CaseRecord>
     */
    public function openForPatientAndDoctor(int $patientId, int $doctorId): Collection
    {
        return CaseRecord::open()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->withCount('treatments')
            ->orderByDesc('opened_at')
            ->get();
    }

    /**
     * Find an open case by id within the active clinic.
     */
    public function findOpen(int $caseId): ?CaseRecord
    {
        return CaseRecord::open()->find($caseId);
    }
}
