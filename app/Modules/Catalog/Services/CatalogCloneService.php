<?php

namespace App\Modules\Catalog\Services;

use App\Models\Product;
use App\Models\Service;
use App\Modules\Catalog\Contracts\CatalogCloneContract;
use App\Scopes\ClinicScope;

/**
 * Copies a clinic's active catalog into a new branch at creation time. Runs inside
 * the caller's transaction (BranchProvisioningService). Stock never transfers: every
 * copied product starts at current_stock = 0 and writes no stock_movements rows.
 */
class CatalogCloneService implements CatalogCloneContract
{
    public function cloneCatalog(int $sourceClinicId, int $targetClinicId): void
    {
        $this->cloneServices($sourceClinicId, $targetClinicId);
        $this->cloneProducts($sourceClinicId, $targetClinicId);
    }

    private function cloneServices(int $sourceClinicId, int $targetClinicId): void
    {
        Service::withoutGlobalScope(ClinicScope::class)
            ->where('clinic_id', $sourceClinicId)
            ->where('is_active', true)
            ->get()
            ->each(function (Service $service) use ($targetClinicId): void {
                $copy = $service->replicate();
                $copy->clinic_id = $targetClinicId;
                $copy->save();
            });
    }

    private function cloneProducts(int $sourceClinicId, int $targetClinicId): void
    {
        Product::withoutGlobalScope(ClinicScope::class)
            ->where('clinic_id', $sourceClinicId)
            ->where('is_active', true)
            ->get()
            ->each(function (Product $product) use ($targetClinicId): void {
                $copy = $product->replicate();
                $copy->clinic_id = $targetClinicId;
                // Inter-branch stock transfer is out of scope — no stock_movements written.
                $copy->current_stock = 0;
                $copy->save();
            });
    }
}
