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
}
