<?php

namespace App\Modules\Catalog\Services;

use App\Models\Clinic;
use App\Models\Service;
use App\Modules\Catalog\Repositories\ServiceRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;

class ServiceCatalogService
{
    public function __construct(
        private ServiceRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return Collection<int, Service>
     */
    public function listForActiveClinic(): Collection
    {
        return $this->repository->allForActiveClinic();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Service
    {
        $clinic = Clinic::findOrFail($this->clinicContext->id());
        $data['vertical_id'] = $clinic->vertical_id;

        // clinic_id is auto-set by BelongsToClinic on create — not set manually.
        return $this->repository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Service $service, array $data): Service
    {
        return $this->repository->update($service, $data);
    }

    public function delete(Service $service): void
    {
        $this->repository->delete($service);
    }
}
