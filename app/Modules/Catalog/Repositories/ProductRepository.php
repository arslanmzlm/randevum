<?php

namespace App\Modules\Catalog\Repositories;

use App\Models\Product;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on Product restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<Product>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(Product::class)
            ->search('name', 'brand', 'category', 'sku')
            ->sort('name', 'price', 'current_stock', 'is_active', 'created_at')
            ->boolean('is_active')
            ->paginate();
    }

    /**
     * All products for the active clinic, newest first.
     *
     * ClinicScope on Product filters to the active clinic automatically.
     *
     * @return Collection<int, Product>
     */
    public function allForActiveClinic(): Collection
    {
        return Product::query()->orderByDesc('id')->get();
    }

    /**
     * Distinct non-null values of a catalog column for the active clinic, sorted — feeds the
     * brand/category autocompletes. ClinicScope keeps it to the active clinic.
     *
     * @return list<string>
     */
    public function distinctValues(string $column): array
    {
        return Product::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    /** ClinicScope keeps this to the active clinic, so another clinic's id resolves to null. */
    public function find(int $id): ?Product
    {
        return Product::find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $product->fill($data)->save();

        return $product;
    }

    public function updateStock(Product $product, int $currentStock): Product
    {
        $product->current_stock = $currentStock;
        $product->save();

        return $product;
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /**
     * Lock the product row for update inside the caller's transaction, so the
     * read-modify-write of current_stock (and the balance_after it feeds) cannot race.
     * withoutGlobalScopes() bypasses ClinicScope — the caller already resolved the id.
     */
    public function lockForUpdate(int $productId): ?Product
    {
        return Product::withoutGlobalScopes()->whereKey($productId)->lockForUpdate()->first();
    }
}
