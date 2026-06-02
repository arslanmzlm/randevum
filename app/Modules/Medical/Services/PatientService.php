<?php

namespace App\Modules\Medical\Services;

use App\Models\Patient;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Repositories\PatientRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PatientService
{
    public function __construct(private PatientRepository $repository) {}

    /**
     * @return LengthAwarePaginator<Patient>
     */
    public function listForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /**
     * Name+phone typeahead lookup for the active clinic.
     *
     * @return Collection<int, Patient>
     */
    public function search(string $term): Collection
    {
        return $this->repository->search($term);
    }

    /**
     * Create a new patient for the active clinic.
     *
     * When the submitted phone already belongs to a soft-deleted patient in this clinic,
     * throws TrashedPhoneConflictException so the caller can surface a restore prompt.
     * clinic_id is auto-set by BelongsToClinic; user_id is always null in MVP.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TrashedPhoneConflictException
     */
    public function create(array $data): Patient
    {
        if (! empty($data['phone'])) {
            $trashed = $this->repository->findTrashedByPhone($data['phone']);

            if ($trashed !== null) {
                throw new TrashedPhoneConflictException($trashed);
            }
        }

        $data['user_id'] = null;

        return $this->repository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Patient $patient, array $data): Patient
    {
        return $this->repository->update($patient, $data);
    }

    public function delete(Patient $patient): void
    {
        $this->repository->delete($patient);
    }

    public function restore(Patient $patient): void
    {
        $this->repository->restore($patient);
    }
}
