<?php

namespace App\Modules\Medical\Repositories;

use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Collection;

class TreatmentRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Treatment
    {
        return Treatment::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Treatment $treatment, array $data): Treatment
    {
        $treatment->fill($data)->save();

        return $treatment;
    }

    /**
     * Find a treatment for the active clinic by id.
     * ClinicScope on Treatment isolates to the active clinic automatically.
     */
    public function find(int $treatmentId): ?Treatment
    {
        return Treatment::find($treatmentId);
    }

    /**
     * Treatments for the patient's history panel, newest first.
     * Loads first service line for the title display; scope restricts to own doctor when needed.
     *
     * @return Collection<int, Treatment>
     */
    public function forPatient(Patient $patient, ?int $doctorId): Collection
    {
        return Treatment::where('patient_id', $patient->id)
            ->when($doctorId !== null, fn ($q) => $q->forDoctor($doctorId))
            ->with([
                'doctor.user',
                'case:id,title',
                'serviceLines' => fn ($q) => $q->orderBy('sort_order')->limit(1),
                'serviceLines.service',
            ])
            ->orderByDesc('created_at')
            ->get();
    }
}
