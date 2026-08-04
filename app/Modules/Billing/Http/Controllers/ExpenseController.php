<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Modules\Billing\Http\Requests\StoreExpenseRequest;
use App\Modules\Billing\Http\Requests\UpdateExpenseRequest;
use App\Modules\Billing\Http\Resources\ExpenseResource;
use App\Modules\Billing\Services\ExpenseService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use App\Support\FilterHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseService $service,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * The clinic's expense list. Everyone with the create permission sees their own rows
     * (no dedicated expenses.viewOwn permission; see ExpensePolicy::create); owner/manager
     * hold expenses.viewAny and default to the whole clinic, with `scope=own` narrowing it
     * back down. The finance report links here instead of repeating the table.
     */
    public function index(Request $request): Response
    {
        $this->authorize('create', Expense::class);

        [$entire, $start, $end] = DateRangeFilter::resolve($request, $this->clinicContext->timezone());
        $category = DateRangeFilter::category($request);

        $canViewAll = $request->user()->can('expenses.viewAny');
        $ownOnly = ! $canViewAll || $request->string('scope')->value() === 'own';

        $paginator = $ownOnly
            ? $this->service->paginateOwn(
                $request->user()->id,
                $entire ? null : $start,
                $entire ? null : $end,
                $category,
            )
            : $this->service->paginateClinic(
                $entire ? null : $start,
                $entire ? null : $end,
                $category,
            );

        return Inertia::render('expenses/Index', [
            'expenses' => ExpenseResource::collection($paginator),
            'categories' => $this->service->suggestions()['categories'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire, 'category' => $category],
            'query' => FilterHelper::requestState(),
            'currency' => $this->clinicContext->currency(),
            'canViewAll' => $canViewAll,
            'scope' => $ownOnly ? 'own' : 'all',
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $this->service->create($request->validated(), $request->user()->id);

        Toast::success(__('expense.created'));

        return back();
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);

        $this->service->update($expense, $request->validated());

        Toast::success(__('expense.updated'));

        return back();
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);

        $this->service->delete($expense);

        Toast::success(__('expense.deleted'));

        return back();
    }
}
