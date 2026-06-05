<?php

namespace App\Modules\Medical\Contracts;

use App\Models\Patient;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;

/**
 * Cross-module seam for creating a patient in the active clinic.
 *
 * Lets Scheduling (inline new-patient booking) register a patient without importing
 * Medical's concrete Service. Bound to PatientService in MedicalServiceProvider.
 */
interface PatientRegistrarContract
{
    /**
     * Create a patient for the active clinic from minimal data.
     *
     * clinic_id is auto-set by BelongsToClinic; user_id is always null in MVP.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TrashedPhoneConflictException When the phone belongs to a soft-deleted patient.
     */
    public function create(array $data): Patient;
}
