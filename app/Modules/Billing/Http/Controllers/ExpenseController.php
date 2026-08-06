<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Modules\Billing\Http\Requests\StoreExpenseRequest;
use App\Modules\Billing\Http\Requests\UpdateExpenseRequest;
use App\Modules\Billing\Http\Resources\ExpenseResource;
use App\Modules\Billing\Services\ExpenseService;
use App\Modules\Core\Support\CrudResponse;
use App\Support\ClinicContext;
use App\Support\DateRangeFilter;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
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

        $editing = $this->service->findForActiveClinic($request->integer('edit'));

        return Inertia::render('expenses/Index', [
            'expenses' => ExpenseResource::collection($paginator),
            'categories' => $this->service->suggestions()['categories'],
            'filters' => ['start' => $start, 'end' => $end, 'entire' => $entire, 'category' => $category],
            'query' => FilterHelper::requestState(),
            'currency' => $this->clinicContext->currency(),
            'canViewAll' => $canViewAll,
            'scope' => $ownOnly ? 'own' : 'all',
            // ?edit=<id> deep link: resolved here so the dialog opens for a row on any page.
            'editing' => CrudResponse::editingProp($request, $editing, ExpenseResource::class),
        ]);
    }

    public function store(StoreExpenseRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Expense::class);

        $expense = $this->service->create($request->validated(), $request->user()->id);

        return CrudResponse::saved(
            $request,
            new ExpenseResource($expense),
            __('expense.created'),
            'expenses.index',
        );
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $expense);

        $saved = $this->service->update($expense, $request->validated());

        return CrudResponse::saved(
            $request,
            new ExpenseResource($saved),
            __('expense.updated'),
            'expenses.index',
        );
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);

        $this->service->delete($expense);

        return CrudResponse::deleted(__('expense.deleted'), 'expenses.index');
    }
}
