<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Product;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductStockRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductCatalogService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
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

        return Inertia::render('products/Index', [
            'products' => ProductResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'is_active' => 'boolean',
            ]),
            'canManage' => $request->user()->can('products.create'),
            'currency' => $this->activeClinicCurrency(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('products/Create', [
            'currency' => $this->activeClinicCurrency(),
            ...$this->catalogService->suggestions(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $this->catalogService->create($request->validated());

        Toast::success(__('messages.product.created'));

        return redirect()->route('products.index');
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('products/Edit', [
            'product' => (new ProductResource($product))->resolve(),
            'currency' => $this->activeClinicCurrency(),
            ...$this->catalogService->suggestions(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $this->catalogService->update($product, $request->validated());

        Toast::success(__('messages.product.updated'));

        return redirect()->route('products.index');
    }

    public function updateStock(UpdateProductStockRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('manageStock', $product);

        $this->catalogService->updateStock($product, $request->validated()['current_stock']);

        Toast::success(__('messages.product.stock_updated'));

        return redirect()->route('products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->catalogService->delete($product);

        Toast::success(__('messages.product.deleted'));

        return redirect()->route('products.index');
    }

    private function activeClinicCurrency(): string
    {
        $clinic = Clinic::find($this->clinicContext->id());

        return $clinic?->currency ?? 'TRY';
    }
}
