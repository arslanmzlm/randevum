<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\CaseStatus;
use App\Models\CaseRecord;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /**
     * Paginated case list for the active clinic.
     *
     * When $doctorIds is null, all cases are included; when set, only cases belonging
     * to the given doctor ids are returned (own/all scoping from the service layer).
     *
     * @param  list<int>|null  $doctorIds
     * @return LengthAwarePaginator<CaseRecord>
     */
    public function paginateForActiveClinic(?array $doctorIds): LengthAwarePaginator
    {
        $query = CaseRecord::query()
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds))
            ->with(['patient:id,first_name,last_name', 'doctor.user'])
            ->withCount('treatments')
            ->orderByDesc('opened_at');

        // Title + patient name are OR'd together so either field satisfies the search.
        $term = request()->input('filter.search');
        if (! blank($term) && is_string($term)) {
            $query->where(function (Builder $q) use ($term): void {
                $q->whereLike('title', "%{$term}%")
                    ->orWhereHas('patient', fn (Builder $p) => $p
                        ->whereLike('first_name', "%{$term}%")
                        ->orWhereLike('last_name', "%{$term}%")
                    );
            });
        }

        return FilterHelper::for($query)
            ->enumMultiple(['status' => CaseStatus::class])
            ->exact('doctor_id')
            ->paginate();
    }

    /**
     * Find a case by id within the active clinic (ClinicScope isolates the tenant).
     */
    public function find(int $caseId): ?CaseRecord
    {
        return CaseRecord::find($caseId);
    }

    /**
     * Cases for a patient's detail page, own/all scoped, with treatment counts.
     *
     * @return Collection<int, CaseRecord>
     */
    public function forPatient(int $patientId, ?int $doctorId): Collection
    {
        return CaseRecord::where('patient_id', $patientId)
            ->when($doctorId !== null, fn ($q) => $q->forDoctor($doctorId))
            ->withCount('treatments')
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'follow_up' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")
            ->orderByDesc('opened_at')
            ->get();
    }
}
