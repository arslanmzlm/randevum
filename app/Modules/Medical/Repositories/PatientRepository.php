<?php

namespace App\Modules\Medical\Repositories;

use App\Enums\Gender;
use App\Models\Patient;
use App\Support\FilterHelper;
use Illuminate\Pagination\LengthAwarePaginator;

class PatientRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on Patient restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<Patient>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(Patient::class)
            ->search('first_name', 'last_name', 'phone')
            ->sort('first_name', 'last_name', 'created_at')
            ->enum(['gender' => Gender::class])
            ->boolean('is_legacy')
            ->paginate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Patient
    {
        return Patient::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data): Patient
    {
        $patient->fill($data)->save();

        return $patient;
    }

    public function delete(Patient $patient): void
    {
        $patient->delete();
    }

    public function restore(Patient $patient): void
    {
        $patient->restore();
    }

    /**
     * Find a soft-deleted patient by E.164 phone in the active clinic.
     * ClinicScope still applies to onlyTrashed() — clinic isolation is preserved.
     */
    public function findTrashedByPhone(string $phone): ?Patient
    {
        return Patient::onlyTrashed()->where('phone', $phone)->first();
    }
}
