<?php

namespace App\Modules\Medical\Services;

use App\Models\Patient;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Repositories\PatientRepository;

/**
 * Thin write seam for registering a patient. Split out of PatientService so the
 * cross-module binding never resolves the orchestration service (which injects
 * Billing's BalanceReaderContract and Core's PatientAppointmentCounterContract).
 * Never add a dependency beyond the repository here.
 */
class PatientRegistrar implements PatientRegistrarContract
{
    public function __construct(private PatientRepository $repository) {}

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
}
