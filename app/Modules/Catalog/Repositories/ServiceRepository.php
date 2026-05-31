<?php

namespace App\Modules\Catalog\Repositories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;

class ServiceRepository
{
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
