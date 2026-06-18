<?php

namespace App\Modules\Medical\Services;

use App\Models\Patient;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Repositories\PatientRepository;
use App\Modules\Medical\Repositories\TreatmentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PatientService implements PatientRegistrarContract
{
    public function __construct(
        private PatientRepository $repository,
        private TreatmentRepository $treatmentRepository,
        private BalanceReaderContract $balanceReader,
    ) {}

    /**
     * @return LengthAwarePaginator<Patient>
     */
    public function listForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /**
     * Remaining balance (billed − paid) per patient for the active clinic, keyed by id —
     * for the list page's balance column. Only non-zero balances are returned (a patient
     * absent from the map is square). billed = Completed treatments (Medical); paid comes
     * through the Billing read seam, never querying Billing tables directly.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, string> patient_id => remaining (decimal string; positive = owes)
     */
    public function remainingBalancesFor(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $billed = $this->treatmentRepository->billedTotalsForPatients($patientIds);
        $paid = $this->balanceReader->paidTotalsForPatients($patientIds);

        $balances = [];

        foreach ($patientIds as $id) {
            $remaining = bcsub($billed[$id] ?? '0', $paid[$id] ?? '0', 2);

            if (bccomp($remaining, '0', 2) !== 0) {
                $balances[$id] = $remaining;
            }
        }

        return $balances;
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

    public function updateNotes(Patient $patient, ?string $notes): Patient
    {
        return $this->repository->update($patient, ['notes' => $notes]);
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
