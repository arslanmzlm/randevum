<?php

namespace App\Modules\Reporting;

use App\Modules\Core\Events\ClinicFinancesChanged;
use App\Modules\Reporting\Listeners\ClearRevenueReportCache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ClinicFinancesChanged::class, ClearRevenueReportCache::class);
    }
}
