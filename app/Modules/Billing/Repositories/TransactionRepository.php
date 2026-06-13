<?php

namespace App\Modules\Billing\Repositories;

use App\Models\Transaction;

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
}
