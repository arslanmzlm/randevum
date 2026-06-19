<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\ServiceLookupContract;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Catalog\Services\ProductCatalogService;
use App\Modules\Catalog\Services\ServiceCatalogService;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockAdjusterContract::class, ProductCatalogService::class);
        $this->app->bind(ServiceLookupContract::class, ServiceCatalogService::class);
    }
}
