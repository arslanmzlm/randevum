<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Http\Resources\StockMovementResource;
use App\Modules\Catalog\Services\StockMovementService;
use App\Support\FilterHelper;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function __construct(private StockMovementService $stockMovementService) {}

    public function index(Product $product): Response
    {
        $this->authorize('viewMovements', $product);

        $paginator = $this->stockMovementService->paginateForProduct($product);

        return Inertia::render('products/Movements', [
            // Inertia props are read unwrapped on the frontend (product.id, not
            // product.data.id) — resolve() strips JsonResource's default 'data' wrap.
            'product' => (new ProductResource($product))->resolve(),
            'movements' => StockMovementResource::collection($paginator),
            'query' => FilterHelper::requestState([
                'reason' => 'string',
                'start_date' => 'string',
                'end_date' => 'string',
            ]),
        ]);
    }
}
