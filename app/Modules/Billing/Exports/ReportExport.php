<?php

namespace App\Modules\Billing\Exports;

use App\Enums\ReportTab;
use App\Modules\Billing\Services\FinanceReportService;
use App\Modules\Core\Services\ReportBreakdownService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Multi-sheet workbook: one sheet per requested tab (a single tab, or every tab for
 * `?tab=all`). Every sheet is unpaginated (all rows for the window) and uses the same
 * date window/sort as the page. Ctor takes the same report services the page
 * controller uses — Excel::download() constructs this class outside the container, so
 * the controller resolves and passes them explicitly.
 */
class ReportExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<int, ReportTab>  $tabs
     * @param  list<int>  $branchIds
     */
    public function __construct(
        private array $tabs,
        private int $clinicId,
        private string $timezone,
        private ?string $startDate,
        private ?string $endDate,
        private string $sort,
        private FinanceReportService $financeReportService,
        private ReportBreakdownService $breakdownService,
        private array $branchIds = [],
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return array_map(fn (ReportTab $tab): object => $this->sheetFor($tab), $this->tabs);
    }

    private function sheetFor(ReportTab $tab): object
    {
        if ($tab->isFinance()) {
            $report = $this->financeReportService->build($this->clinicId, $this->timezone, $this->startDate, $this->endDate);

            return new FinanceSheet($report['revenue'], $report['expense'], $report['net']);
        }

        $result = $this->breakdownService->build(
            $tab,
            $this->timezone,
            $this->startDate,
            $this->endDate,
            $this->sort,
            perPage: 0,
            paginate: false,
            branchIds: $this->branchIds,
        );

        return new ReportSheet($tab, $result['data'], $result['totals']);
    }
}
