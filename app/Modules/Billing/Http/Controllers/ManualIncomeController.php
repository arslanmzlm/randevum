<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Modules\Billing\Http\Requests\StoreManualIncomeRequest;
use App\Modules\Billing\Http\Resources\ManualIncomeResource;
use App\Modules\Billing\Services\ManualIncomeService;
use App\Modules\Core\Support\CrudResponse;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ManualIncomeController extends Controller
{
    public function __construct(
        private ManualIncomeService $service,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Transaction::class);

        // The list itself is gated by transactions.viewAny; seeing every user's income (not
        // just rows this viewer created) additionally requires reports.revenue.
        $ownerUserId = $request->user()->can('reports.revenue') ? null : $request->user()->id;

        [$entire, $start, $end] = DateRangeFilter::resolve($request, $this->clinicContext->timezone());
        $category = DateRangeFilter::category($request);

        $paginator = $this->service->paginate(
            $ownerUserId,
            $entire ? null : $start,
            $entire ? null : $end,
            $category,
            $this->clinicContext->timezone(),
        );

        return Inertia::render('incomes/Index', [
            'incomes' => ManualIncomeResource::collection($paginator),
            'categories' => $this->service->suggestions($ownerUserId)['categories'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire, 'category' => $category],
            'query' => FilterHelper::requestState(),
            'currency' => $this->clinicContext->currency(),
            'deleteWindowSeconds' => (int) config('platform.edit_windows.transaction_delete'),
        ]);
    }

    public function store(StoreManualIncomeRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $income = $this->service->create($request->validated(), $request->user());

        return CrudResponse::saved(
            $request,
            new ManualIncomeResource($income),
            __('income.created'),
            'incomes.index',
        );
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        $this->service->delete($transaction);

        return CrudResponse::deleted(__('income.deleted'), 'incomes.index');
    }
}
