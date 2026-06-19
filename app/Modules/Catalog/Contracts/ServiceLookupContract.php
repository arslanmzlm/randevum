<?php

namespace App\Modules\Catalog\Contracts;

use Illuminate\Support\Collection;

/**
 * Read seam for catalog service data used by other modules (Scheduling slot-duration
 * resolution, booking/treatment form selects). Consumers import this contract; never the
 * concrete ServiceCatalogService or the Service model.
 */
interface ServiceLookupContract
{
    /**
     * Duration in minutes for the given catalog service, or null when the service
     * is not found or has no duration configured.
     */
    public function durationMinutes(int $serviceId): ?int;

    /**
     * Active services projected for the appointment booking form select, ordered by name.
     *
     * @return Collection<int, array{id: int, name: string, duration_minutes: int|null, price: string}>
     */
    public function activeForBooking(): Collection;

    /**
     * Active services projected for the treatment line editor — carries the clinical
     * template fields (default_*) that prefill the treatment form, ordered by name.
     *
     * @return Collection<int, array{id: int, name: string, price: string, default_complaint: string|null, default_diagnosis: string|null, default_treatment_process: string|null}>
     */
    public function activeForTreatment(): Collection;
}
