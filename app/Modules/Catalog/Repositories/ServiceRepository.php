<?php

namespace App\Modules\Catalog\Repositories;

use App\Models\Service;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceRepository
{
    /**
     * Paginated list for the active clinic with server-side search / sort / filter.
     * ClinicScope on Service restricts results to the active clinic automatically.
     *
     * @return LengthAwarePaginator<Service>
     */
    public function paginateForActiveClinic(): LengthAwarePaginator
    {
        return FilterHelper::for(Service::class)
            ->search('name', 'description')
            ->sort('name', 'price', 'is_active', 'created_at')
            ->boolean('is_active')
            ->paginate();
    }

    /**
     * All services for the active clinic, newest first.
     *
     * ClinicScope on Service filters to the active clinic automatically.
     *
     * @return Collection<int, Service>
     */
    public function allForActiveClinic(): Collection
    {
        return Service::query()->orderByDesc('id')->get();
    }

    /** ClinicScope keeps this to the active clinic, so another clinic's id resolves to null. */
    public function find(int $id): ?Service
    {
        return Service::find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Service
    {
        return Service::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Service $service, array $data): Service
    {
        $service->fill($data)->save();

        return $service;
    }

    public function delete(Service $service): void
    {
        $service->delete();
    }
}
