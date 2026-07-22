<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Expense;
use App\Modules\Billing\Http\Resources\ExpenseResource;
use App\Modules\Billing\Services\ExpenseService;
use App\Modules\Billing\Services\FinanceReportService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use App\Support\FilterHelper;
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

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        [$entire, $start, $end] = DateRangeFilter::resolve($request, $clinic->timezone);
        $category = DateRangeFilter::category($request);

        $windowStart = $entire ? null : $start;
        $windowEnd = $entire ? null : $end;

        $report = $this->reportService->build($clinic->id, $clinic->timezone, $windowStart, $windowEnd);
        $expenses = $this->expenseService->paginateClinic($windowStart, $windowEnd, $category);

        return Inertia::render('reports/Finance', [
            'revenue' => $report['revenue'],
            'expense' => $report['expense'],
            'net' => $report['net'],
            'expenses' => ExpenseResource::collection($expenses),
            'categories' => $this->expenseService->suggestions()['categories'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire, 'category' => $category],
            'query' => FilterHelper::requestState(),
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

        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $this->reportService->clearCache($clinic->id);

        Toast::success(__('revenue.cache_cleared'));

        return back();
    }
}
