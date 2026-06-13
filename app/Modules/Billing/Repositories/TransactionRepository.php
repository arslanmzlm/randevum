<?php

namespace App\Modules\Billing\Repositories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

class TransactionRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * Sum of all transaction amounts for a treatment (the derived "paid" balance).
     * Returns a string to preserve decimal precision for bcmath comparisons.
     */
    public function paidTotalForTreatment(int $treatmentId): string
    {
        return (string) Transaction::where('treatment_id', $treatmentId)->sum('amount');
    }

    /**
     * Sum of all transaction amounts for a patient in the active clinic.
     * BelongsToClinic global scope provides tenant isolation automatically.
     */
    public function paidTotalForPatient(int $patientId): string
    {
        return (string) Transaction::where('patient_id', $patientId)->sum('amount');
    }

    /**
     * All transactions for a patient in the active clinic, newest first.
     * BelongsToClinic global scope provides tenant isolation automatically.
     *
     * @return Collection<int, Transaction>
     */
    public function forPatient(int $patientId): Collection
    {
        return Transaction::where('patient_id', $patientId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
    }
}
