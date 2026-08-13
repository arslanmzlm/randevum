<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Enums\StockAdjustmentMode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductStockRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductCatalogService;
use App\Modules\Core\Support\CrudResponse;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ProductCatalogService $catalogService,
        private ClinicContext $clinicContext,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $paginator = $this->catalogService->paginateForActiveClinic();

        $editing = $this->catalogService->findForActiveClinic($request->integer('edit'));

        return Inertia::render('products/Index', [
            'products' => ProductResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'is_active' => 'boolean',
            ]),
            'currency' => $this->clinicContext->currency(),
            // Brand/category autocompletes live in the form dialog on this page now.
            ...$this->catalogService->suggestions(),
            // ?edit=<id> deep link: resolved here so the dialog opens for a row on any page.
            'editing' => CrudResponse::editingProp($request, $editing, ProductResource::class),
        ]);
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', Product::class);

        return to_route('products.index', ['new' => 1]);
    }

    public function store(StoreProductRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->catalogService->create($request->validated());

        return CrudResponse::saved(
            $request,
            new ProductResource($product),
            __('messages.product.created'),
            'products.index',
        );
    }

    public function edit(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        return to_route('products.index', ['edit' => $product->id]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $product);

        $saved = $this->catalogService->update($product, $request->validated());

        return CrudResponse::saved(
            $request,
            new ProductResource($saved),
            __('messages.product.updated'),
            'products.index',
        );
    }

    public function updateStock(UpdateProductStockRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('manageStock', $product);

        $validated = $request->validated();
        $reason = $request->stockReason();
        $note = $validated['note'] ?? null;

        if ($request->stockMode() === StockAdjustmentMode::Movement) {
            $this->catalogService->recordStockMovement(
                $product,
                (int) $validated['quantity'],
                $reason,
                $note,
                $request->user(),
            );
        } else {
            $this->catalogService->updateStock(
                $product,
                (int) $validated['current_stock'],
                $reason,
                $note,
                $request->user(),
            );
        }

        Toast::success(__('messages.product.stock_updated'));

        return to_route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->catalogService->delete($product);

        return CrudResponse::deleted(__('messages.product.deleted'), 'products.index');
    }
}
