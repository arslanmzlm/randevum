<?php

namespace App\Modules\Reporting\Listeners;

use App\Modules\Core\Events\ClinicFinancesChanged;
use App\Modules\Reporting\Services\FinanceReportService;

/**
 * Drops the moved clinic's cached revenue windows so the next report view recomputes.
 * Runs synchronously — a tag flush is cheap, and deferring it to a queue would reopen
 * the stale window the flush exists to close.
 */
class ClearRevenueReportCache
{
    public function __construct(private FinanceReportService $financeReportService) {}

    public function handle(ClinicFinancesChanged $event): void
    {
        $this->financeReportService->clearCache($event->clinicId);
    }
}
