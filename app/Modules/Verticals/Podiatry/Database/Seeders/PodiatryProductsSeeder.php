<?php

namespace App\Modules\Verticals\Podiatry\Database\Seeders;

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Vertical;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use Illuminate\Database\Seeder;

class PodiatryProductsSeeder extends Seeder
{
    public function __construct(private StockAdjusterContract $stockMovements) {}

    /**
     * Default products for podiatry clinics. Idempotent — safe to re-run.
     *
     * @var array<int, array{name: string, category: string, unit: string, price: float, current_stock: int}>
     */
    private const PRODUCTS = [
        ['name' => 'Diyabetik Ayak Kremi', 'category' => 'Bakım Ürünleri', 'unit' => 'adet', 'price' => 120.00, 'current_stock' => 20],
        ['name' => 'Silikon Topuk Pedi', 'category' => 'Ortopedik Ürünler', 'unit' => 'çift', 'price' => 85.00, 'current_stock' => 30],
        ['name' => 'Parmak Ortezi', 'category' => 'Ortopedik Ürünler', 'unit' => 'adet', 'price' => 65.00, 'current_stock' => 25],
        ['name' => 'Topuk Dolgusu (Silikon)', 'category' => 'Ortopedik Ürünler', 'unit' => 'çift', 'price' => 95.00, 'current_stock' => 15],
        ['name' => 'Antifungal Sprey', 'category' => 'İlaç ve Tedavi', 'unit' => 'adet', 'price' => 75.00, 'current_stock' => 18],
        ['name' => 'Nasır Bandı', 'category' => 'Bakım Ürünleri', 'unit' => 'kutu', 'price' => 35.00, 'current_stock' => 40],
        ['name' => 'Steril Gazlı Bez', 'category' => 'Tıbbi Malzeme', 'unit' => 'kutu', 'price' => 25.00, 'current_stock' => 50],
        ['name' => 'Elastik Bandaj', 'category' => 'Tıbbi Malzeme', 'unit' => 'adet', 'price' => 20.00, 'current_stock' => 60],
        ['name' => 'Tırnak Düzleştirici Plak', 'category' => 'Ortopedik Ürünler', 'unit' => 'adet', 'price' => 150.00, 'current_stock' => 10],
        ['name' => 'Ayak Deodorantı Spreyi', 'category' => 'Bakım Ürünleri', 'unit' => 'adet', 'price' => 55.00, 'current_stock' => 22],
    ];

    public function run(): void
    {
        $podiatry = Vertical::where('slug', 'podiatry')->first();

        if ($podiatry === null) {
            return;
        }

        $clinics = Clinic::where('vertical_id', $podiatry->id)->get();

        foreach ($clinics as $clinic) {
            foreach (self::PRODUCTS as $productData) {
                $product = Product::withoutGlobalScopes()->firstOrCreate(
                    ['clinic_id' => $clinic->id, 'name' => $productData['name']],
                    array_merge($productData, [
                        'vertical_id' => $podiatry->id,
                        'is_active' => true,
                    ]),
                );

                // Keyed on the product + `initial` reason rather than wasRecentlyCreated, so a
                // database seeded before the ledger existed gets its opening movement too.
                $hasInitial = StockMovement::withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('reason', StockMovementReason::Initial)
                    ->exists();

                if (! $hasInitial) {
                    $this->stockMovements->recordInitial($product, $productData['current_stock']);
                }
            }
        }
    }
}
