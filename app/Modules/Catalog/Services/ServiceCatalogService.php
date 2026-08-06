<?php

namespace App\Modules\Catalog\Services;

use App\Models\Clinic;
use App\Models\Service;
use App\Modules\Catalog\Contracts\ServiceLookupContract;
use App\Modules\Catalog\Repositories\ServiceRepository;
use App\Support\ClinicContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceCatalogService implements ServiceLookupContract
{
    public function __construct(
        private ServiceRepository $repository,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * @return LengthAwarePaginator<Service>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic();
    }

    /**
     * @return Collection<int, Service>
     */
    public function listForActiveClinic(): Collection
    {
        return $this->repository->allForActiveClinic();
    }

    /** The active clinic's service by id, or null — backs the list page's `?edit=` deep link. */
    public function findForActiveClinic(?int $id): ?Service
    {
        return $id === null || $id <= 0
            ? null
            : $this->repository->find($id);
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

    public function durationMinutes(int $serviceId): ?int
    {
        return Service::find($serviceId)?->duration_minutes;
    }

    public function activeForBooking(): \Illuminate\Support\Collection
    {
        return Service::active()
            ->select(['id', 'name', 'duration_minutes', 'price'])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'duration_minutes' => $s->duration_minutes,
                'price' => $s->price,
            ]);
    }

    public function activeForTreatment(): \Illuminate\Support\Collection
    {
        return Service::active()
            ->select(['id', 'name', 'price', 'default_complaint', 'default_diagnosis', 'default_treatment_process'])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'price' => $s->price,
                'default_complaint' => $s->default_complaint,
                'default_diagnosis' => $s->default_diagnosis,
                'default_treatment_process' => $s->default_treatment_process,
            ]);
    }
}
