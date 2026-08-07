<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Enums\ReportTab;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Modules\Billing\Exports\ReportExport;
use App\Modules\Billing\Services\FinanceReportService;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Modules\Core\Services\ReportBreakdownService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /** Allowed `per_page` values for a breakdown tab's server-side pagination. */
    private const PER_PAGE_OPTIONS = [10, 20, 50];

    private const DEFAULT_PER_PAGE = 20;

    private const DEFAULT_SORT = '-amount';

    public function __construct(
        private FinanceReportService $financeReportService,
        private ReportBreakdownService $breakdownService,
        private ClinicContext $clinicContext,
        private ClinicMembershipService $membership,
    ) {}

    /**
     * GET /reports — single, tabbed report page (finance + breakdowns). Only the
     * active tab's data is computed per request, so a large date range never pulls
     * every breakdown's rows at once.
     */
    public function index(Request $request): Response
    {
        $this->authorize('reports.revenue');

        $clinic = $this->clinicContext->clinicOrFail();
        [$entire, $start, $end] = DateRangeFilter::resolve($request, $clinic->timezone);
        $tab = ReportTab::tryFrom((string) $request->string('tab')) ?? ReportTab::Finance;
        [$sort, $perPage] = $this->sortAndPerPage($request);

        $branchIds = $this->membership->branchIdsForTenant($request->user(), $clinic->tenant_id);
        $multiBranch = count($branchIds) > 1;

        // Tek şubeli klinikte hiçbir yeni kontrol görünmez: silently fall back rather
        // than error when the tab is requested without a second branch.
        if ($tab === ReportTab::Branch && ! $multiBranch) {
            $tab = ReportTab::Finance;
        }

        $windowStart = $entire ? null : $start;
        $windowEnd = $entire ? null : $end;

        $revenue = null;
        $expense = null;
        $net = null;
        $breakdown = null;
        $resolvedSort = self::DEFAULT_SORT;

        if ($tab->isFinance()) {
            $this->authorize('viewAny', Expense::class);

            $report = $this->financeReportService->build($clinic->id, $clinic->timezone, $windowStart, $windowEnd);
            $revenue = $report['revenue'];
            $expense = $report['expense'];
            $net = $report['net'];
        } else {
            $result = $this->breakdownService->build($tab, $clinic->timezone, $windowStart, $windowEnd, $sort, $perPage, paginate: true, branchIds: $branchIds);

            $breakdown = [
                'data' => $result['data'],
                'meta' => $result['meta'],
                'totals' => $result['totals'],
            ];
            $resolvedSort = $result['sort'];
        }

        return Inertia::render('reports/Index', [
            'tab' => $tab->value,
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire],
            'currency' => $this->clinicContext->currency(),
            'revenue' => $revenue,
            'expense' => $expense,
            'net' => $net,
            'breakdown' => $breakdown,
            'query' => ['sort' => $resolvedSort, 'per_page' => $perPage],
            'multiBranch' => $multiBranch,
        ]);
    }

    /**
     * GET /reports/export — downloads the selected tab (or every tab, `?tab=all`) as
     * `.xlsx`, using the same date window/sort as the page but ignoring pagination
     * (every row for the window is written).
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('reports.revenue');

        $clinic = $this->clinicContext->clinicOrFail();
        [$entire, $start, $end] = DateRangeFilter::resolve($request, $clinic->timezone);
        [$sort] = $this->sortAndPerPage($request);

        $branchIds = $this->membership->branchIdsForTenant($request->user(), $clinic->tenant_id);
        $multiBranch = count($branchIds) > 1;

        $tabParam = (string) $request->string('tab');
        $tabs = $tabParam === 'all' ? ReportTab::cases() : [ReportTab::tryFrom($tabParam) ?? ReportTab::Finance];

        // Same "tek şubeli klinikte hiçbir yeni kontrol görünmez" fallback as index():
        // drop the branch tab from ?tab=all, or fall back an explicit ?tab=branch.
        if (! $multiBranch) {
            $tabs = $tabParam === 'all'
                ? array_values(array_filter($tabs, fn (ReportTab $t): bool => $t !== ReportTab::Branch))
                : array_map(fn (ReportTab $t): ReportTab => $t === ReportTab::Branch ? ReportTab::Finance : $t, $tabs);
        }

        // The finance sheet carries the expense total and the expense-by-category block,
        // so exporting it needs the same gate index() applies before rendering that tab.
        if (in_array(ReportTab::Finance, $tabs, true)) {
            $this->authorize('viewAny', Expense::class);
        }

        $windowStart = $entire ? null : $start;
        $windowEnd = $entire ? null : $end;

        $tabLabel = $tabParam === 'all' ? 'all' : $tabs[0]->value;
        $filename = $entire
            ? "report-{$tabLabel}-all.xlsx"
            : "report-{$tabLabel}-{$start}-{$end}.xlsx";

        return Excel::download(
            new ReportExport(
                $tabs,
                $clinic->id,
                $clinic->timezone,
                $windowStart,
                $windowEnd,
                $sort,
                $this->financeReportService,
                $this->breakdownService,
                $branchIds,
            ),
            $filename,
        );
    }

    /**
     * Drop every cached revenue window for the active clinic so the next view recomputes fresh.
     * The expense/breakdown sides are never cached, so only the revenue leg needs clearing.
     */
    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorize('reports.revenue');

        $clinic = $this->clinicContext->clinicOrFail();
        $this->financeReportService->clearCache($clinic->id);

        Toast::success(__('revenue.cache_cleared'));

        return back();
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function sortAndPerPage(Request $request): array
    {
        $sort = trim((string) $request->string('sort'));
        $sort = $sort === '' ? self::DEFAULT_SORT : $sort;

        $perPage = $request->integer('per_page', self::DEFAULT_PER_PAGE);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        return [$sort, $perPage];
    }
}
