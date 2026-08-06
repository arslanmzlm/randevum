<?php

namespace App\Modules\Catalog\Services;

use App\Models\Product;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Catalog\Repositories\ProductRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductCatalogService implements StockAdjusterContract
{
    public function __construct(
        private ProductRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return LengthAwarePaginator<Product>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /**
     * @return Collection<int, Product>
     */
    public function listForActiveClinic(): Collection
    {
        return $this->repository->allForActiveClinic();
    }

    /**
     * Existing brand/category values for this clinic — suggestions for the create/edit
     * autocompletes (no separate brand/category tables; the clinic reuses what it typed before).
     *
     * @return array{brands: list<string>, categories: list<string>}
     */
    public function suggestions(): array
    {
        return [
            'brands' => $this->repository->distinctValues('brand'),
            'categories' => $this->repository->distinctValues('category'),
        ];
    }

    /** The active clinic's product by id, or null — backs the list page's `?edit=` deep link. */
    public function findForActiveClinic(?int $id): ?Product
    {
        return $id === null || $id <= 0
            ? null
            : $this->repository->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $clinic = $this->clinicContext->clinicOrFail();
        $data['vertical_id'] = $clinic->vertical_id;

        // Passing null explicitly would violate the NOT NULL constraint — fall back to the
        // column default (0) when no initial stock level is supplied.
        $data['current_stock'] = $data['current_stock'] ?? 0;

        // clinic_id is auto-set by BelongsToClinic on create — not set manually.
        return $this->repository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return $this->repository->update($product, $data);
    }

    public function updateStock(Product $product, int $currentStock): Product
    {
        return $this->repository->updateStock($product, $currentStock);
    }

    public function delete(Product $product): void
    {
        $this->repository->delete($product);
    }

    /**
     * Atomically adjust current_stock by $delta. Negative delta deducts (may go below 0).
     */
    public function adjust(int $productId, int $delta): void
    {
        $this->repository->adjustStock($productId, $delta);
    }
}
