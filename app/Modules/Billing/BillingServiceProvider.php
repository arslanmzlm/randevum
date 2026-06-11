<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Services\PaymentService;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRecorderContract::class, PaymentService::class);
    }
}
