<?php

namespace App\Modules\Compliance;

use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Modules\Compliance\Services\ConsentService;
use Illuminate\Support\ServiceProvider;

class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConsentRecorderContract::class, ConsentService::class);
    }
}
