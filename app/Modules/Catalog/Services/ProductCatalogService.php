<?php

namespace App\Modules\Catalog\Services;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\User;
use App\Modules\Catalog\Contracts\ProductLookupContract;
use App\Modules\Catalog\Repositories\ProductRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductCatalogService implements ProductLookupContract
{
    public function __construct(
        private ProductRepository $repository,
        private StockMovementService $stockMovementService,
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
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, price: string, unit: string, current_stock: int}>
     */
    public function activeForTreatment(): \Illuminate\Support\Collection
    {
        return $this->repository->activeForTreatment()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->price,
                'unit' => $p->unit,
                'current_stock' => $p->current_stock,
            ])
            ->values();
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
     * Creates the product with its opening stock already on it, then records a matching
     * `initial` movement in the same transaction — only when the resolved stock is non-zero,
     * so a product created with no stock leaves no ledger noise.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $clinic = $this->clinicContext->clinicOrFail();
        $data['vertical_id'] = $clinic->vertical_id;

        // Passing null explicitly would violate the NOT NULL constraint — fall back to the
        // column default (0) when no initial stock level is supplied.
        $initialStock = (int) ($data['current_stock'] ?? 0);
        $data['current_stock'] = $initialStock;

        return DB::transaction(function () use ($data, $initialStock): Product {
            // clinic_id is auto-set by BelongsToClinic on create — not set manually.
            $product = $this->repository->create($data);

            if ($initialStock !== 0) {
                // The product already carries the initial stock value — record the movement
                // directly (bypassing StockMovementService::record()'s own current_stock
                // read-modify-write) so the delta is not double-applied.
                $this->stockMovementService->recordInitial($product, $initialStock);
            }

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        return $this->repository->update($product, $data);
    }

    /**
     * Sets current_stock to an absolute value via the single stock funnel, recording the
     * matching movement in the same transaction.
     */
    public function updateStock(
        Product $product,
        int $currentStock,
        StockMovementReason $reason,
        ?string $note,
        User $actor,
    ): Product {
        $this->stockMovementService->setAbsolute($product, $currentStock, $reason, $note, $actor);

        return $product->fresh();
    }

    public function delete(Product $product): void
    {
        $this->repository->delete($product);
    }
}
