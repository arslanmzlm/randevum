<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Modules\Billing\Services\ExpenseService;
use App\Modules\Billing\Services\FinanceReportService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __construct(
        private FinanceReportService $reportService,
        private ExpenseService $expenseService,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('reports.revenue');
        $this->authorize('viewAny', Expense::class);

        $clinic = $this->clinicContext->clinicOrFail();
        [$entire, $start, $end] = DateRangeFilter::resolve($request, $clinic->timezone);
        $category = DateRangeFilter::category($request);

        $windowStart = $entire ? null : $start;
        $windowEnd = $entire ? null : $end;

        $report = $this->reportService->build($clinic->id, $clinic->timezone, $windowStart, $windowEnd);

        // Report only: the expense rows themselves live on /expenses, so this page ships the
        // breakdowns and the date window, not a second paginated list of the same data.
        return Inertia::render('reports/Finance', [
            'revenue' => $report['revenue'],
            'expense' => $report['expense'],
            'net' => $report['net'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire],
            'currency' => $this->clinicContext->currency(),
        ]);
    }

    /**
     * Drop every cached revenue window for the active clinic so the next view recomputes fresh.
     * The expense side is never cached, so only the revenue leg needs clearing.
     */
    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorize('reports.revenue');

        $clinic = $this->clinicContext->clinicOrFail();
        $this->reportService->clearCache($clinic->id);

        Toast::success(__('revenue.cache_cleared'));

        return back();
    }
}
