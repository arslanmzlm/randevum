<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Contracts\CatalogCloneContract;
use App\Modules\Catalog\Contracts\ServiceLookupContract;
use App\Modules\Catalog\Contracts\StockAdjusterContract;
use App\Modules\Catalog\Services\CatalogCloneService;
use App\Modules\Catalog\Services\ServiceCatalogService;
use App\Modules\Catalog\Services\StockMovementService;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockAdjusterContract::class, StockMovementService::class);
        $this->app->bind(ServiceLookupContract::class, ServiceCatalogService::class);
        $this->app->bind(CatalogCloneContract::class, CatalogCloneService::class);
    }
}
