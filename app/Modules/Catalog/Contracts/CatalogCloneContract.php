<?php

namespace App\Modules\Catalog\Contracts;

/**
 * Read seam for cloning a clinic's active catalog (services + products) into a new
 * branch. Consumers (Identity's branch provisioning) import this contract; never the
 * concrete CatalogCloneService or the Service/Product models directly.
 */
interface CatalogCloneContract
{
    public function cloneCatalog(int $sourceClinicId, int $targetClinicId): void;
}
