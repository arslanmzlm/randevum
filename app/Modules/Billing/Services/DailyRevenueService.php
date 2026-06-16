<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Repositories\TransactionRepository;
use App\Modules\Core\Contracts\DailyRevenueContract;

class DailyRevenueService implements DailyRevenueContract
{
    public function __construct(
        private TransactionRepository $repository,
    ) {}

    public function collectedTodayForActiveClinic(string $timezone): string
    {
        return $this->repository->collectedTodayTotal($timezone);
    }
}
