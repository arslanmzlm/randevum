<?php

namespace App\Modules\Catalog\Repositories;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\FilterHelper;
use Illuminate\Pagination\LengthAwarePaginator;

class StockMovementRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StockMovement
    {
        return StockMovement::create($data);
    }

    /**
     * Server-side paginated ledger for one product, newest-first.
     * ClinicScope isolates the tenant — no manual clinic_id filter.
     *
     * @return LengthAwarePaginator<StockMovement>
     */
    public function paginateForProduct(Product $product, string $timezone): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->where('product_id', $product->id)
            ->with(['creator', 'treatment.patient'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return FilterHelper::for($query)
            ->enumMultiple(['reason' => StockMovementReason::class])
            ->dateRange('created_at', 'start_date', 'end_date', $timezone)
            ->paginate();
    }
}
