<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Services\BalanceService;
use App\Modules\Billing\Services\DailyRevenueService;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Core\Contracts\DailyRevenueContract;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRecorderContract::class, PaymentService::class);
        $this->app->bind(BalanceReaderContract::class, BalanceService::class);
        $this->app->bind(DailyRevenueContract::class, DailyRevenueService::class);
    }
}
