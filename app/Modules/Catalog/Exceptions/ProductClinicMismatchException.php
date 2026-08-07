<?php

namespace App\Modules\Catalog\Exceptions;

use RuntimeException;

/**
 * Thrown by ProductRepository::lockForUpdate() when the locked row exists but belongs to
 * a clinic other than the active one. Kept distinct from a plain "product not found"
 * RuntimeException so a genuine tenant-isolation violation is never mistaken for (or
 * silently swallowed as) a missing row — the two have different causes and should never
 * share a catch block or a log line.
 */
class ProductClinicMismatchException extends RuntimeException
{
    public function __construct(int $productId, int $expectedClinicId, int $actualClinicId)
    {
        parent::__construct(
            "Product [{$productId}] belongs to clinic [{$actualClinicId}], not the active clinic [{$expectedClinicId}]."
        );
    }
}
