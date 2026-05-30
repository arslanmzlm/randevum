<?php

namespace App\Modules\Core\Services;

use App\Models\Clinic;
use App\Modules\Core\Repositories\ClinicRepository;

/**
 * Applies validated profile updates to a clinic.
 *
 * Immutable fields (vertical_id, tenant_id, timezone, locale, currency,
 * is_active, onboarded_at) are never in the validated payload, so they
 * cannot change regardless of what fill() receives.
 */
class ClinicProfileService
{
    public function __construct(private ClinicRepository $repository) {}

    /**
     * @param  array<string, mixed>  $validated  Output of UpdateClinicRequest::validated()
     */
    public function update(Clinic $clinic, array $validated): void
    {
        $this->repository->update($clinic, $validated);
    }
}
